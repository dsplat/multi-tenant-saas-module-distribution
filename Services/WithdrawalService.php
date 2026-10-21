<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;
use MultiTenantSaas\Modules\Distribution\Models\Withdrawal;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 提现服务
 *
 * 完整流程：
 * 申请 request   -> 校验余额/最低额度，扣减可提现，创建 pending 提现单
 * 审核 audit     -> 通过 approved / 驳回 rejected（驳回退回余额）
 * 打款 payout    -> 创建打款单，调用渠道驱动；成功 paid / 失败 failed（退回余额）
 * 人工确认 confirmPaid -> 手动渠道线下打款完成后置为 paid
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
     * 创建打款单并调用渠道驱动：
     * - 自动渠道成功 -> withdrawal=paid, payout_order=success
     * - 自动渠道失败 -> withdrawal=failed, payout_order=failed, 退回余额
     * - 手动渠道     -> withdrawal 保持 approved, payout_order=pending，等待人工确认
     */
    public function payout(int $tenantId, int $withdrawalId, array $accountOverride = []): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        $payoutConfig = $this->payoutConfigService->getConfig($tenantId);
        $driver = $this->driverResolver->resolve($payoutConfig->channel);

        return DB::transaction(function () use ($tenantId, $withdrawalId, $payoutConfig, $driver, $accountOverride) {
            $withdrawal = $this->findLocked($tenantId, $withdrawalId);

            if ($withdrawal->status !== Withdrawal::STATUS_APPROVED) {
                throw new UnprocessableEntityHttpException('Withdrawal not approved');
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

            // 手动渠道：不自动打款，等待人工确认
            if (! $driver->isAutomatic()) {
                return $withdrawal->fresh();
            }

            $result = $driver->transfer($payoutOrder, $payoutConfig);

            if ($result['success']) {
                $payoutOrder->update([
                    'status' => PayoutOrder::STATUS_SUCCESS,
                    'channel_trade_no' => $result['channel_trade_no'],
                    'paid_at' => now(),
                ]);
                $withdrawal->update([
                    'status' => Withdrawal::STATUS_PAID,
                    'paid_at' => now(),
                ]);
            } else {
                $payoutOrder->update([
                    'status' => PayoutOrder::STATUS_FAILED,
                    'fail_reason' => $result['fail_reason'],
                ]);
                $withdrawal->update(['status' => Withdrawal::STATUS_FAILED]);

                // 打款失败退回余额
                $this->ledgerService->withdrawReturn(
                    $tenantId,
                    (int) $withdrawal->distributor_id,
                    (int) $withdrawal->amount,
                    (int) $withdrawal->withdrawal_id,
                    '打款失败退回'
                );
            }

            return $withdrawal->fresh();
        });
    }

    /**
     * 人工确认已打款（手动渠道线下打款完成后调用）
     */
    public function confirmPaid(int $tenantId, int $withdrawalId, ?string $channelTradeNo = null): Withdrawal
    {
        TenantContext::setTenantId((string) $tenantId);

        return DB::transaction(function () use ($tenantId, $withdrawalId, $channelTradeNo) {
            $withdrawal = $this->findLocked($tenantId, $withdrawalId);

            if (! in_array($withdrawal->status, [Withdrawal::STATUS_APPROVED, Withdrawal::STATUS_PENDING], true)) {
                throw new UnprocessableEntityHttpException('Withdrawal cannot be confirmed');
            }

            if ($withdrawal->payout_order_id) {
                PayoutOrder::where('payout_order_id', $withdrawal->payout_order_id)->update([
                    'status' => PayoutOrder::STATUS_SUCCESS,
                    'channel_trade_no' => $channelTradeNo,
                    'paid_at' => now(),
                ]);
            }

            $withdrawal->update([
                'status' => Withdrawal::STATUS_PAID,
                'audited_at' => $withdrawal->audited_at ?? now(),
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
            // 微信转账所需 openid（来自分销员关联客户，由上层补充）
            'openid' => $withdrawal->remark ? null : null,
        ];
    }
}
