<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Distribution\Models\DistributorLedger;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 分销账本服务
 *
 * 维护分销员三个余额字段（事务 + 悲观锁保证一致性），金额单位为整数「分」：
 * - total_commission     累计佣金（已结算入账总额）
 * - available_commission 可提现余额
 * - frozen_commission    冻结佣金（待结算）
 *
 * 资金流转：
 * 计佣 -> frozen（冻结）
 * 结算 -> frozen 转 available（入账，累计 +）
 * 冲销 -> 扣减 frozen（退款，未结算部分作废）
 * 提现 -> 扣减 available
 * 退回 -> available +（提现失败/驳回）
 */
class DistributorLedgerService
{
    /**
     * 计佣入账（冻结）：订单支付成功时调用
     */
    public function freezeCommission(int $tenantId, int $distributorId, int $amount, ?string $relatedType = null, ?int $relatedId = null, ?string $description = null): DistributorLedger
    {
        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Commission amount must be positive');
        }

        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            $distributor->frozen_commission = (int) $distributor->frozen_commission + $amount;
        }, DistributorLedger::TYPE_COMMISSION, $amount, $relatedType, $relatedId, $description ?? '佣金入账（待结算）');
    }

    /**
     * 结算：冻结佣金转可提现（结算期满调用）
     */
    public function settleCommission(int $tenantId, int $distributorId, int $amount, ?string $relatedType = null, ?int $relatedId = null, ?string $description = null): DistributorLedger
    {
        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Settle amount must be positive');
        }

        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            if ((int) $distributor->frozen_commission < $amount) {
                throw new UnprocessableEntityHttpException('Insufficient frozen commission');
            }
            $distributor->frozen_commission = (int) $distributor->frozen_commission - $amount;
            $distributor->available_commission = (int) $distributor->available_commission + $amount;
            $distributor->total_commission = (int) $distributor->total_commission + $amount;
        }, DistributorLedger::TYPE_COMMISSION, $amount, $relatedType, $relatedId, $description ?? '佣金结算');
    }

    /**
     * 冲销：退款时扣减冻结佣金（未结算部分作废）
     */
    public function cancelCommission(int $tenantId, int $distributorId, int $amount, ?string $relatedType = null, ?int $relatedId = null, ?string $description = null): DistributorLedger
    {
        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Cancel amount must be positive');
        }

        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            // 仅能冲销冻结中的佣金；已结算部分不退（业务约定）
            $deduct = min((int) $distributor->frozen_commission, $amount);
            $distributor->frozen_commission = (int) $distributor->frozen_commission - $deduct;
        }, DistributorLedger::TYPE_ADJUST, -$amount, $relatedType, $relatedId, $description ?? '佣金冲销（退款）');
    }

    /**
     * 提现扣减可提现余额
     */
    public function withdraw(int $tenantId, int $distributorId, int $amount, ?int $withdrawalId = null, ?string $description = null): DistributorLedger
    {
        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Withdraw amount must be positive');
        }

        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            if ((int) $distributor->available_commission < $amount) {
                throw new UnprocessableEntityHttpException('Insufficient available commission');
            }
            $distributor->available_commission = (int) $distributor->available_commission - $amount;
        }, DistributorLedger::TYPE_WITHDRAW, -$amount, 'withdrawal', $withdrawalId, $description ?? '提现');
    }

    /**
     * 提现退回（驳回/打款失败）
     */
    public function withdrawReturn(int $tenantId, int $distributorId, int $amount, ?int $withdrawalId = null, ?string $description = null): DistributorLedger
    {
        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Return amount must be positive');
        }

        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            $distributor->available_commission = (int) $distributor->available_commission + $amount;
        }, DistributorLedger::TYPE_WITHDRAW_RETURN, $amount, 'withdrawal', $withdrawalId, $description ?? '提现退回');
    }

    /**
     * 人工调整余额（正负皆可，单位分）
     */
    public function adjust(int $tenantId, int $distributorId, int $amount, ?string $description = null): DistributorLedger
    {
        return $this->mutate($tenantId, $distributorId, function (Distributor $distributor) use ($amount) {
            $distributor->available_commission = (int) $distributor->available_commission + $amount;
            if ((int) $distributor->available_commission < 0) {
                throw new UnprocessableEntityHttpException('Adjust would make balance negative');
            }
        }, DistributorLedger::TYPE_ADJUST, $amount, null, null, $description ?? '人工调整');
    }

    /**
     * 账本流水分页
     */
    public function paginate(int $tenantId, int $distributorId, int $perPage = 20): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $paginator = DistributorLedger::where('tenant_id', $tenantId)
            ->where('distributor_id', $distributorId)
            ->orderByDesc('created_at')
            ->orderByDesc('ledger_id')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * 核心：事务 + 悲观锁变更余额并写流水
     *
     * @param  callable(Distributor):void  $mutator  余额变更逻辑（直接修改模型属性，无需 save）
     */
    protected function mutate(
        int $tenantId,
        int $distributorId,
        callable $mutator,
        string $type,
        int $amount,
        ?string $relatedType,
        ?int $relatedId,
        ?string $description
    ): DistributorLedger {
        TenantContext::setTenantId((string) $tenantId);

        return DB::transaction(function () use ($tenantId, $distributorId, $mutator, $type, $amount, $relatedType, $relatedId, $description) {
            /** @var Distributor $distributor */
            $distributor = Distributor::where('distributor_id', $distributorId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $distributor) {
                throw new UnprocessableEntityHttpException('Distributor not found');
            }

            $mutator($distributor);
            $distributor->save();

            return DistributorLedger::create([
                'tenant_id' => $tenantId,
                'distributor_id' => $distributorId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $distributor->available_commission,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'description' => $description,
            ]);
        });
    }
}
