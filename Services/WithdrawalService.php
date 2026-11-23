<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Contracts\PayoutDriverContract;
use MultiTenantSaas\Modules\Distribution\Contracts\PayoutStatusQueryContract;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;
use MultiTenantSaas\Modules\Distribution\Models\Withdrawal;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 提现服务
 *
 * 完整流程：
 * 申请 request   -> 校验余额/最低额度，扣减可提现，创建 pending 提现单
 * 审核 audit     -> 通过 approved / 驳回 rejected（驳回退回余额）
 * 打款 payout    -> 先提交打款单（稳定 trade_no），再调渠道；受理/超时一律 processing
 * 终态 reconcile -> 按原 trade_no 查询渠道，确认 success 才 paid，确认 failed 才退余额
 * 人工确认 confirmPaid -> 仅限已审批、关联人工打款单、仍待确认的提现
 */
class WithdrawalService
{
    public function __construct(
        protected DistributionConfigService $configService,
        protected DistributorLedgerService $ledgerService,
        protected PayoutConfigService $payoutConfigService,
        protected PayoutDriverResolver $driverResolver,
    ) {}

    /**
     * 分销员申请提现
     */
    public function request(int $tenantId, int $distributorId, array $data): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        $config = $this->configService->getConfig($tenantId);

        $distributor = Distributor::where('distributor_id', $distributorId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $distributor || ! $distributor->isActive()) {
            throw new UnprocessableEntityHttpException('Distributor not active');
        }

        $amount = (int) ($data['amount'] ?? 0);
        $minAmount = (int) $config['withdraw_min_amount'];

        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Invalid withdrawal amount');
        }
        if ($minAmount > 0 && $amount < $minAmount) {
            throw new UnprocessableEntityHttpException("Minimum withdrawal amount is {$minAmount}");
        }
        if ($amount > (int) $distributor->available_commission) {
            throw new UnprocessableEntityHttpException('Insufficient available commission');
        }

        // 手续费
        $feeRate = (float) $config['withdraw_fee_rate'];
        $fee = (int) round($amount * $feeRate / 100);
        $actual = $amount - $fee;

        return DB::transaction(function () use ($tenantId, $distributorId, $data, $amount, $fee, $actual) {
            // 先扣减可提现余额（悲观锁防并发超额提现）
            $this->ledgerService->withdraw($tenantId, $distributorId, $amount, null, '提现申请冻结');

            return Withdrawal::create([
                'tenant_id' => $tenantId,
                'distributor_id' => $distributorId,
                'amount' => $amount,
                'fee' => $fee,
                'actual_amount' => $actual,
                'status' => Withdrawal::STATUS_PENDING,
                'account_type' => $data['account_type'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'account_openid' => $data['openid'] ?? $data['account_openid'] ?? null,
                'remark' => $data['remark'] ?? null,
            ]);
        });
    }

    /**
     * 审核提现（通过/驳回）
     */
    public function audit(int $tenantId, int $withdrawalId, bool $approved, ?string $remark = null): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        return DB::transaction(function () use ($tenantId, $withdrawalId, $approved, $remark) {
            $withdrawal = $this->findLocked($tenantId, $withdrawalId);

            if ($withdrawal->status !== Withdrawal::STATUS_PENDING) {
                throw new UnprocessableEntityHttpException('Withdrawal not pending');
            }

            if ($approved) {
                $withdrawal->update([
                    'status' => Withdrawal::STATUS_APPROVED,
                    'audit_remark' => $remark,
                    'audited_at' => now(),
                ]);

                return $withdrawal->fresh();
            }

            // 驳回：退回余额
            $withdrawal->update([
                'status' => Withdrawal::STATUS_REJECTED,
                'audit_remark' => $remark,
                'audited_at' => now(),
            ]);

            $this->ledgerService->withdrawReturn(
                $tenantId,
                (int) $withdrawal->distributor_id,
                (int) $withdrawal->amount,
                (int) $withdrawal->withdrawal_id,
                '提现驳回退回'
            );

            return $withdrawal->fresh();
        });
    }

    /**
     * 执行打款（针对已审核通过的提现单）
     *
     * 两阶段：
     * 1. 事务内只落本地打款单（稳定 trade_no）并提交；
     * 2. 事务外调用渠道。受理/超时 → withdrawal=processing、payout_order=processing，
     *    绝不写 paid、绝不退款；只有渠道明确失败才置 failed 并退余额。
     *
     * - 手动渠道：withdrawal=approved, payout_order=pending，等待人工确认
     * - 自动渠道：受理后 processing，由 reconcile() 按原单号确认终态
     */
    public function payout(int $tenantId, int $withdrawalId, array $accountOverride = []): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        $payoutConfig = $this->payoutConfigService->getConfig($tenantId);

        // 阶段一：本地单据提交。渠道调用不得位于此事务内，否则渠道成功后本地回滚
        // 会留下「已受理但本地无记录」的窗口，重试又生成新的商户单号。
        [$withdrawal, $payoutOrder, $created] = DB::transaction(function () use ($tenantId, $withdrawalId, $payoutConfig, $accountOverride) {
            $withdrawal = $this->findLocked($tenantId, $withdrawalId);

            if (! in_array($withdrawal->status, [Withdrawal::STATUS_APPROVED, Withdrawal::STATUS_PROCESSING], true)) {
                throw new UnprocessableEntityHttpException('Withdrawal not approved');
            }

            if ($withdrawal->payout_order_id) {
                $existing = PayoutOrder::where('payout_order_id', $withdrawal->payout_order_id)
                    ->where('withdrawal_id', $withdrawal->withdrawal_id)
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    throw new UnprocessableEntityHttpException('Withdrawal payout order not found');
                }

                if ($existing->status === PayoutOrder::STATUS_SUCCESS) {
                    // 渠道已成功但提现单尚未回写：同一事务内补齐，绝不重复打款。
                    if ($withdrawal->status !== Withdrawal::STATUS_PAID) {
                        $withdrawal->update([
                            'status' => Withdrawal::STATUS_PAID,
                            'paid_at' => $existing->paid_at ?? now(),
                        ]);
                    }

                    return [$withdrawal->fresh(), $existing, false];
                }

                if (in_array($existing->status, [PayoutOrder::STATUS_PENDING, PayoutOrder::STATUS_PROCESSING], true)) {
                    // 已有在途单：复用并只查询终态，禁止重新生成商户单号。
                    return [$withdrawal, $existing, false];
                }

                throw new UnprocessableEntityHttpException('Withdrawal has a failed payout order');
            }

            if ($withdrawal->status === Withdrawal::STATUS_PROCESSING) {
                throw new UnprocessableEntityHttpException('Withdrawal processing without payout order');
            }

            if (! empty($accountOverride['openid']) && $withdrawal->account_openid !== $accountOverride['openid']) {
                $withdrawal->update(['account_openid' => $accountOverride['openid']]);
            }

            $accountInfo = array_merge($this->buildAccountInfo($withdrawal), $accountOverride);

            $payoutOrder = PayoutOrder::create([
                'tenant_id' => $tenantId,
                'withdrawal_id' => $withdrawal->withdrawal_id,
                'channel' => $payoutConfig->channel,
                'amount' => $withdrawal->actual_amount,
                'account_info' => $accountInfo,
                'trade_no' => PayoutOrder::generateTradeNo(),
                'status' => PayoutOrder::STATUS_PENDING,
            ]);

            $withdrawal->update(['payout_order_id' => $payoutOrder->payout_order_id]);

            return [$withdrawal->fresh(), $payoutOrder, true];
        });

        // 已有打款单的渠道必须与当前租户配置一致：渠道切换后用新配置的驱动重试，
        // 会把人工单送进自动转账，或用错商户身份查单。显式失败，要求先恢复原渠道配置。
        if ($payoutOrder->channel !== $payoutConfig->channel) {
            throw new UnprocessableEntityHttpException(
                'Payout channel changed since the order was created; restore the original channel config before retrying'
            );
        }

        $driver = $this->driverResolver->resolve($payoutOrder->channel);

        // 手动渠道：不自动打款，等待人工确认
        if (! $driver->isAutomatic()) {
            return $withdrawal;
        }

        if (! $created) {
            // 上次事务提交后、渠道调用前中断：同一 out_batch_no 重提是幂等的，
            // 渠道已受理时会按原单号报重复，不会产生第二笔；随后仍走查询收敛。
            if ($payoutOrder->status === PayoutOrder::STATUS_PENDING && $payoutOrder->channel_trade_no === null) {
                $result = $driver->transfer($payoutOrder, $payoutConfig);

                return $this->applyTransferResult($tenantId, $payoutOrder, $result);
            }

            // 已受理/在途：只查终态，不重复提交。
            return $this->reconcilePayoutOrder($tenantId, $payoutOrder, $payoutConfig, $driver);
        }

        $result = $driver->transfer($payoutOrder, $payoutConfig);

        return $this->applyTransferResult($tenantId, $payoutOrder, $result);
    }

    /**
     * 按打款单号查询渠道终态（运维/定时补偿入口）
     */
    public function reconcile(int $tenantId, int $payoutOrderId): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        $order = PayoutOrder::where('payout_order_id', $payoutOrderId)->first();

        if (! $order) {
            throw new UnprocessableEntityHttpException('Payout order not found');
        }

        $withdrawal = Withdrawal::where('withdrawal_id', $order->withdrawal_id)->first();

        if (! $withdrawal) {
            throw new UnprocessableEntityHttpException('Withdrawal not found');
        }

        if (in_array($order->status, [PayoutOrder::STATUS_SUCCESS, PayoutOrder::STATUS_FAILED], true)) {
            return $withdrawal;
        }

        $payoutConfig = $this->payoutConfigService->getConfig($tenantId);

        if ($payoutConfig->channel !== $order->channel) {
            throw new UnprocessableEntityHttpException(
                'Payout channel changed since the order was created; restore the original channel config before reconciling'
            );
        }

        $driver = $this->driverResolver->resolve($order->channel);

        if (! $driver->isAutomatic()) {
            throw new UnprocessableEntityHttpException('Manual payout requires confirmPaid');
        }

        return $this->reconcilePayoutOrder($tenantId, $order, $payoutConfig, $driver);
    }

    /**
     * 按提现单号查询其打款终态
     */
    public function reconcileByWithdrawal(int $tenantId, int $withdrawalId): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        $withdrawal = $this->find($tenantId, $withdrawalId);

        if (! $withdrawal->payout_order_id) {
            throw new UnprocessableEntityHttpException('Withdrawal has no payout order');
        }

        return $this->reconcile($tenantId, (int) $withdrawal->payout_order_id);
    }

    /**
     * 人工确认已打款（仅手动渠道线下打款完成后调用）
     *
     * 必须同时满足：提现已审批、关联本提现单的人工打款单、打款单仍待确认。
     * 不再接受 pending 提现，也不再补写 audited_at（审核痕迹只能由 audit() 产生）。
     */
    public function confirmPaid(int $tenantId, int $withdrawalId, ?string $channelTradeNo = null): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        return DB::transaction(function () use ($tenantId, $withdrawalId, $channelTradeNo) {
            $withdrawal = $this->findLocked($tenantId, $withdrawalId);

            if ($withdrawal->status !== Withdrawal::STATUS_APPROVED) {
                throw new UnprocessableEntityHttpException('Withdrawal not approved');
            }

            if (! $withdrawal->payout_order_id) {
                throw new UnprocessableEntityHttpException('Withdrawal has no payout order');
            }

            $payoutOrder = PayoutOrder::where('payout_order_id', $withdrawal->payout_order_id)
                ->where('withdrawal_id', $withdrawal->withdrawal_id)
                ->lockForUpdate()
                ->first();

            if (! $payoutOrder) {
                throw new UnprocessableEntityHttpException('Withdrawal payout order not found');
            }

            if ($payoutOrder->status !== PayoutOrder::STATUS_PENDING) {
                throw new UnprocessableEntityHttpException('Payout order not pending');
            }

            $driver = $this->driverResolver->resolve($payoutOrder->channel);
            if ($driver->isAutomatic()) {
                throw new UnprocessableEntityHttpException('Automatic payout cannot be confirmed manually');
            }

            $payoutOrder->update([
                'status' => PayoutOrder::STATUS_SUCCESS,
                'channel_trade_no' => $channelTradeNo,
                'paid_at' => now(),
            ]);

            $withdrawal->update([
                'status' => Withdrawal::STATUS_PAID,
                'paid_at' => now(),
            ]);

            return $withdrawal->fresh();
        });
    }

    /**
     * 提现单分页（B端可按状态筛选；C端按分销员筛选）
     */
    public function paginate(int $tenantId, array $filters = [], int $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Withdrawal::where('tenant_id', $tenantId)->with('distributor');

        if (! empty($filters['distributor_id'])) {
            $query->where('distributor_id', $filters['distributor_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('withdrawal_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function find(int $tenantId, int $withdrawalId): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        return Withdrawal::where('withdrawal_id', $withdrawalId)
            ->where('tenant_id', $tenantId)
            ->with('distributor')
            ->firstOrFail();
    }

    /**
     * 渠道返回结果落库：success/failed 才转换提现终态；其余一律保留 processing。
     */
    protected function applyTransferResult(int $tenantId, PayoutOrder $order, array $result): Withdrawal
    {
        $state = $this->normalizeState($result['state'] ?? null, (bool) ($result['success'] ?? false));

        return match ($state) {
            'success' => $this->markPayoutSuccess($tenantId, $order, $result['channel_trade_no'] ?? null),
            'failed' => $this->markPayoutFailed($tenantId, $order, $result['fail_reason'] ?? null),
            default => $this->markPayoutProcessing(
                $tenantId,
                $order,
                $result['channel_trade_no'] ?? null,
                $result['fail_reason'] ?? null,
            ),
        };
    }

    /**
     * 查询渠道终态并落库。
     */
    protected function reconcilePayoutOrder(
        int $tenantId,
        PayoutOrder $order,
        PayoutConfig $payoutConfig,
        PayoutDriverContract $driver,
    ): Withdrawal {
        if ($order->status === PayoutOrder::STATUS_SUCCESS) {
            return $this->markPayoutSuccess($tenantId, $order, $order->channel_trade_no);
        }

        if ($order->status === PayoutOrder::STATUS_FAILED) {
            return $this->markPayoutFailed($tenantId, $order, $order->fail_reason);
        }

        if (! $driver instanceof PayoutStatusQueryContract) {
            // 无查询能力：保持处理中，等待人工确认/对账，不能猜终态。
            return $this->markPayoutProcessing($tenantId, $order, $order->channel_trade_no, '渠道不支持终态查询');
        }

        $result = $driver->query($order, $payoutConfig);
        $state = $this->normalizeState($result['state'] ?? null, false);

        return match ($state) {
            'success' => $this->markPayoutSuccess($tenantId, $order, $result['channel_trade_no'] ?? null),
            'failed' => $this->markPayoutFailed($tenantId, $order, $result['fail_reason'] ?? null),
            default => $this->markPayoutProcessing(
                $tenantId,
                $order,
                $result['channel_trade_no'] ?? null,
                $result['fail_reason'] ?? null,
            ),
        };
    }

    /**
     * 渠道已受理/结果未知：提现与打款单都停在 processing，禁止结算与退款。
     */
    protected function markPayoutProcessing(int $tenantId, PayoutOrder $order, ?string $channelTradeNo, ?string $failReason): Withdrawal
    {
        DB::transaction(function () use ($order, $channelTradeNo, $failReason) {
            $locked = PayoutOrder::where('payout_order_id', $order->payout_order_id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, [PayoutOrder::STATUS_PENDING, PayoutOrder::STATUS_PROCESSING], true)) {
                return;
            }

            $attempts = (int) $locked->reconcile_attempts + 1;

            $locked->update([
                'status' => PayoutOrder::STATUS_PROCESSING,
                'channel_trade_no' => $channelTradeNo ?: $locked->channel_trade_no,
                'fail_reason' => $failReason ?: $locked->fail_reason,
                'reconcile_attempts' => $attempts,
                'next_reconcile_at' => now()->addMinutes($this->reconcileBackoffMinutes($attempts)),
            ]);

            $withdrawal = Withdrawal::where('withdrawal_id', $locked->withdrawal_id)->lockForUpdate()->first();

            if ($withdrawal && $withdrawal->status === Withdrawal::STATUS_APPROVED) {
                $withdrawal->update(['status' => Withdrawal::STATUS_PROCESSING]);
            }
        }, 3);

        return $this->reloadWithdrawal($order);
    }

    /**
     * 渠道明确成功：打款单 success + 提现单 paid，二者同一事务。
     */
    protected function markPayoutSuccess(int $tenantId, PayoutOrder $order, ?string $channelTradeNo): Withdrawal
    {
        DB::transaction(function () use ($order, $channelTradeNo) {
            $locked = PayoutOrder::where('payout_order_id', $order->payout_order_id)->lockForUpdate()->first();

            if (! $locked) {
                throw new UnprocessableEntityHttpException('Payout order not found');
            }

            if ($locked->status === PayoutOrder::STATUS_FAILED) {
                throw new UnprocessableEntityHttpException('Payout order already failed; refusing to flip to success');
            }

            $paidAt = $locked->status === PayoutOrder::STATUS_SUCCESS
                ? ($locked->paid_at ?? now())
                : now();

            if ($locked->status !== PayoutOrder::STATUS_SUCCESS) {
                $locked->update([
                    'status' => PayoutOrder::STATUS_SUCCESS,
                    'channel_trade_no' => $channelTradeNo ?: $locked->channel_trade_no,
                    'paid_at' => $paidAt,
                    'fail_reason' => null,
                    'next_reconcile_at' => null,
                ]);
            }

            $withdrawal = Withdrawal::where('withdrawal_id', $locked->withdrawal_id)->lockForUpdate()->first();

            if (! $withdrawal) {
                throw new UnprocessableEntityHttpException('Withdrawal not found');
            }

            if ($withdrawal->status === Withdrawal::STATUS_PAID) {
                return;
            }

            if (! in_array($withdrawal->status, [Withdrawal::STATUS_APPROVED, Withdrawal::STATUS_PROCESSING], true)) {
                throw new UnprocessableEntityHttpException('Withdrawal already refunded; cannot mark paid');
            }

            $withdrawal->update([
                'status' => Withdrawal::STATUS_PAID,
                'paid_at' => $paidAt,
            ]);
        }, 3);

        return $this->reloadWithdrawal($order);
    }

    /**
     * 渠道明确失败：打款单 failed + 提现单 failed，且只在首次转换时退回余额。
     */
    protected function markPayoutFailed(int $tenantId, PayoutOrder $order, ?string $failReason): Withdrawal
    {
        DB::transaction(function () use ($tenantId, $order, $failReason) {
            $locked = PayoutOrder::where('payout_order_id', $order->payout_order_id)->lockForUpdate()->first();

            if (! $locked) {
                throw new UnprocessableEntityHttpException('Payout order not found');
            }

            if ($locked->status === PayoutOrder::STATUS_SUCCESS) {
                throw new UnprocessableEntityHttpException('Payout order already succeeded; refusing to flip to failed');
            }

            if ($locked->status !== PayoutOrder::STATUS_FAILED) {
                $locked->update([
                    'status' => PayoutOrder::STATUS_FAILED,
                    'fail_reason' => $failReason ?: $locked->fail_reason,
                    'next_reconcile_at' => null,
                ]);
            }

            $withdrawal = Withdrawal::where('withdrawal_id', $locked->withdrawal_id)->lockForUpdate()->first();

            if (! $withdrawal) {
                throw new UnprocessableEntityHttpException('Withdrawal not found');
            }

            if ($withdrawal->status === Withdrawal::STATUS_FAILED) {
                return; // 已退过余额，幂等
            }

            if (! in_array($withdrawal->status, [Withdrawal::STATUS_APPROVED, Withdrawal::STATUS_PROCESSING], true)) {
                throw new UnprocessableEntityHttpException('Withdrawal already paid; refusing to refund');
            }

            $withdrawal->update(['status' => Withdrawal::STATUS_FAILED]);

            // 打款失败退回余额（仅首次 APPROVED/PROCESSING → FAILED 时执行）
            $this->ledgerService->withdrawReturn(
                $tenantId,
                (int) $withdrawal->distributor_id,
                (int) $withdrawal->amount,
                (int) $withdrawal->withdrawal_id,
                '打款失败退回'
            );
        }, 3);

        return $this->reloadWithdrawal($order);
    }

    /**
     * 查询退避：5 / 10 / 20 / 40 / 60 分钟封顶。
     */
    private function reconcileBackoffMinutes(int $attempts): int
    {
        return (int) min(5 * (2 ** max(0, $attempts - 1)), 60);
    }

    private function reloadWithdrawal(PayoutOrder $order): Withdrawal
    {
        return Withdrawal::where('withdrawal_id', $order->withdrawal_id)->firstOrFail();
    }

    private function normalizeState(?string $state, bool $legacySuccess): string
    {
        if (in_array($state, ['success', 'failed', 'processing', 'unknown'], true)) {
            return $state;
        }

        // 兼容没有 state 的旧驱动：成功只按「已受理」处理，失败按「结果未知」处理。
        // 绝不因为旧驱动的 false 直接退款——那可能发生在渠道已受理之后。
        return $legacySuccess ? 'processing' : 'unknown';
    }

    /**
     * 悲观锁读取提现单
     */
    protected function findLocked(int $tenantId, int $withdrawalId): Withdrawal
    {
        $withdrawal = Withdrawal::where('withdrawal_id', $withdrawalId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first();

        if (! $withdrawal) {
            throw new UnprocessableEntityHttpException('Withdrawal not found');
        }

        return $withdrawal;
    }

    /**
     * 构建打款账户信息
     */
    protected function buildAccountInfo(Withdrawal $withdrawal): array
    {
        return [
            'account_type' => $withdrawal->account_type,
            'bank_name' => $withdrawal->bank_name,
            'bank_account' => $withdrawal->bank_account,
            'account_name' => $withdrawal->account_name,
            // 微信转账所需 openid；未提供时驱动会在受理前明确失败（可安全退款）。
            'openid' => $withdrawal->account_openid ?: null,
        ];
    }
}
