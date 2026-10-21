<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Distribution\Models\CommissionRecord;
use MultiTenantSaas\Modules\Distribution\Models\Distributor;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;

/**
 * 佣金计算引擎
 *
 * 订阅框架 OrderPaid 事件（兼容 App 层别名 OrderPaidEvent），按买家上级链（合规锁定 2 级）逐级匹配规则计佣：
 * 1. 校验分销是否启用
 * 2. 解析买家上级链（1级直接上级 / 2级间接上级）
 * 3. 每级匹配佣金规则并计算金额
 * 4. 写佣金流水（pending）+ 冻结入账
 * 5. 自购返佣由配置 self_buy_rebate 控制
 *
 * 幂等：commission_records 唯一键 (order_type, order_id, level, distributor_id)
 */
class CommissionEngine
{
    public function __construct(
        protected DistributionConfigService $configService,
        protected DistributionRelationService $relationService,
        protected CommissionRuleService $ruleService,
        protected DistributorLedgerService $ledgerService,
    ) {}

    /**
     * 处理订单支付成功事件，执行计佣
     *
     * @return CommissionRecord[] 本次生成的佣金流水
     */
    public function handleOrderPaid(OrderPaid $event): array
    {
        $tenantId = $event->tenantId;

        if (! $this->configService->isEnabled($tenantId)) {
            return [];
        }

        if (! $event->buyerUserId || $event->amount <= 0) {
            return [];
        }

        $config = $this->configService->getConfig($tenantId);
        $records = [];

        // 解析上级链 [level => Distributor]
        $chain = $this->relationService->getUplineChain($tenantId, $event->buyerUserId);

        // 自购返佣：买家本人是分销员（按 user_id 直查其分销身份）且配置开启时，作为 1 级处理
        if ($config['self_buy_rebate']) {
            $self = Distributor::where('tenant_id', $tenantId)
                ->where('user_id', $event->buyerUserId)
                ->first();
            if ($self && $self->isActive() && ! isset($chain[1])) {
                $chain = [1 => $self] + $chain;
            }
        }

        foreach ($chain as $level => $distributor) {
            $rule = $this->ruleService->matchRule($tenantId, $level, [
                'order_type' => $event->orderType,
                'goods_id' => $event->context['goods_id'] ?? null,
            ]);

            if (! $rule) {
                continue; // 该层级无适用规则，不计佣
            }

            $commission = $rule->calculate($event->amount);
            if ($commission <= 0) {
                continue;
            }

            $record = $this->createCommissionRecord($event, $distributor, $level, $commission, $rule->commission_rule_id);
            if ($record) {
                $records[] = $record;
            }
        }

        if ($records) {
            Log::info("CommissionEngine: {$event->orderType}#{$event->orderId} generated " . count($records) . ' commission(s)');
        }

        return $records;
    }

    /**
     * 创建佣金流水 + 冻结入账（幂等：唯一键冲突跳过）
     */
    protected function createCommissionRecord(OrderPaid $event, Distributor $distributor, int $level, int $commission, int $ruleId): ?CommissionRecord
    {
        TenantContext::setTenantId((string) $event->tenantId);

        try {
            return DB::transaction(function () use ($event, $distributor, $level, $commission, $ruleId) {
                $record = CommissionRecord::create([
                    'tenant_id' => $event->tenantId,
                    'distributor_id' => $distributor->distributor_id,
                    'order_type' => $event->orderType,
                    'order_id' => $event->orderId,
                    'order_no' => $event->orderNo,
                    'buyer_user_id' => $event->buyerUserId,
                    'level' => $level,
                    'order_amount' => $event->amount,
                    'commission_amount' => $commission,
                    'rule_id' => $ruleId,
                    'status' => CommissionRecord::STATUS_PENDING,
                ]);

                // 冻结入账
                $this->ledgerService->freezeCommission(
                    $event->tenantId,
                    (int) $distributor->distributor_id,
                    $commission,
                    'commission_record',
                    (int) $record->commission_record_id,
                    "{$level}级佣金（待结算）"
                );

                return $record;
            });
        } catch (QueryException $e) {
            // 唯一键冲突 → 已计佣，幂等跳过
            Log::warning("CommissionEngine: duplicate commission skipped for {$event->orderType}#{$event->orderId} L{$level}");

            return null;
        }
    }

    /**
     * 结算到期佣金（pending 且创建满 settle_days）
     *
     * 由定时任务调用。将冻结佣金转可提现。
     *
     * @return int 结算笔数
     */
    public function settleDueCommissions(int $tenantId): int
    {
        TenantContext::setTenantId((string) $tenantId);

        $settleDays = (int) $this->configService->getConfig($tenantId)['settle_days'];
        $dueTime = now()->subDays($settleDays);

        $records = CommissionRecord::where('tenant_id', $tenantId)
            ->where('status', CommissionRecord::STATUS_PENDING)
            ->where('created_at', '<=', $dueTime)
            ->get();

        $count = 0;
        foreach ($records as $record) {
            DB::transaction(function () use ($tenantId, $record) {
                $record->update([
                    'status' => CommissionRecord::STATUS_SETTLED,
                    'settled_at' => now(),
                ]);

                $this->ledgerService->settleCommission(
                    $tenantId,
                    (int) $record->distributor_id,
                    (int) $record->commission_amount,
                    'commission_record',
                    (int) $record->commission_record_id,
                    "{$record->level}级佣金结算"
                );
            });
            $count++;
        }

        return $count;
    }

    /**
     * 订单退款冲销佣金
     *
     * 将该订单所有 pending 佣金标记 cancelled 并冲销冻结余额；
     * 已结算佣金按业务约定不退回（仅记录日志）。
     */
    public function cancelByOrder(int $tenantId, string $orderType, int $orderId): int
    {
        TenantContext::setTenantId((string) $tenantId);

        $records = CommissionRecord::where('tenant_id', $tenantId)
            ->where('order_type', $orderType)
            ->where('order_id', $orderId)
            ->where('status', CommissionRecord::STATUS_PENDING)
            ->get();

        $count = 0;
        foreach ($records as $record) {
            DB::transaction(function () use ($tenantId, $record) {
                $record->update([
                    'status' => CommissionRecord::STATUS_CANCELLED,
                    'cancel_reason' => '订单退款',
                ]);

                $this->ledgerService->cancelCommission(
                    $tenantId,
                    (int) $record->distributor_id,
                    (int) $record->commission_amount,
                    'commission_record',
                    (int) $record->commission_record_id,
                    "{$record->level}级佣金冲销（退款）"
                );
            });
            $count++;
        }

        return $count;
    }
}
