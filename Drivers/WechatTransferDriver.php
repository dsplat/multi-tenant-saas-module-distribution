<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Drivers;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Distribution\Contracts\PayoutDriverContract;
use MultiTenantSaas\Modules\Distribution\Contracts\PayoutStatusQueryContract;
use MultiTenantSaas\Modules\Distribution\Models\PayoutConfig;
use MultiTenantSaas\Modules\Distribution\Models\PayoutOrder;
use Yansongda\Pay\Pay;

/**
 * 微信商家转账到零钱驱动（自动打款）
 *
 * 基于 yansongda/pay v3 的微信 V3 商家转账接口
 * （transfer/batches，单笔明细）。租户级商户配置来自 payout_configs。
 *
 * account_info 约定：['openid' => '收款用户openid', 'user_name' => '可选真实姓名']
 *
 * 重要：`transfer()` 拿到 batch_id 只代表渠道**受理**，不是到账。
 * - state=accepted：已受理，必须由 `query()` 按 out_batch_no/out_detail_no 复核终态
 * - state=failed：受理前就被拒绝（缺 openid/配置不全），可安全退回余额
 * - state=unknown：超时/网络/未知响应，可能已受理，绝不能直接退款
 */
class WechatTransferDriver implements PayoutDriverContract, PayoutStatusQueryContract
{
    public function getChannel(): string
    {
        return PayoutConfig::CHANNEL_WECHAT_TRANSFER;
    }

    public function isAutomatic(): bool
    {
        return true;
    }

    public function transfer(PayoutOrder $order, PayoutConfig $config): array
    {
        $account = $order->account_info ?? [];
        $openid = $account['openid'] ?? null;

        if (! $openid) {
            return [
                'success' => false,
                'state' => 'failed',
                'channel_trade_no' => null,
                'fail_reason' => '缺少收款用户 openid',
            ];
        }

        $extra = $config->extra ?? [];
        $appId = $extra['app_id'] ?? '';

        if (! $appId || ! $config->merchant_id) {
            return [
                'success' => false,
                'state' => 'failed',
                'channel_trade_no' => null,
                'fail_reason' => '打款配置不完整（app_id/merchant_id）',
            ];
        }

        $amountFen = (int) $order->amount;

        $payload = [
            'appid' => $appId,
            'out_batch_no' => $order->trade_no,
            'batch_name' => $extra['batch_name'] ?? '佣金提现',
            'batch_remark' => $extra['batch_remark'] ?? '分销佣金提现',
            'total_amount' => $amountFen,
            'total_num' => 1,
            'transfer_detail_list' => [
                [
                    'out_detail_no' => $order->trade_no,
                    'transfer_amount' => $amountFen,
                    'transfer_remark' => $extra['batch_remark'] ?? '分销佣金提现',
                    'openid' => $openid,
                ],
            ],
        ];

        try {
            $pay = Pay::wechat($this->buildConfig($config, $appId));
            $result = $pay->transfer($payload);
            $data = $this->toArray($result);

            $batchId = $data['batch_id'] ?? null;
            if ($batchId) {
                // 已受理，不代表到账；终态交给 query()。
                return [
                    'success' => true,
                    'state' => 'accepted',
                    'channel_trade_no' => (string) $batchId,
                    'fail_reason' => null,
                ];
            }

            // 没有 batch_id：无法判断渠道是否受理，按未知处理，禁止据此退款。
            return [
                'success' => false,
                'state' => 'unknown',
                'channel_trade_no' => null,
                'fail_reason' => '微信转账未返回 batch_id',
            ];
        } catch (\Throwable $e) {
            Log::error('WechatTransferDriver: transfer failed', [
                'trade_no' => $order->trade_no,
                'error' => $e->getMessage(),
            ]);

            // 超时/网络异常可能发生在渠道已受理之后，必须保留处理中。
            return [
                'success' => false,
                'state' => 'unknown',
                'channel_trade_no' => null,
                'fail_reason' => mb_substr($e->getMessage(), 0, 250),
            ];
        }
    }

    /**
     * 按原商户单号查询明细终态。
     *
     * 使用 out_batch_no + out_detail_no（两者都取本地 trade_no）查询单笔明细；
     * 查询失败返回 unknown，调用方保持 processing，绝不据此退款。
     */
    public function query(PayoutOrder $order, PayoutConfig $config): array
    {
        $extra = $config->extra ?? [];
        $appId = $extra['app_id'] ?? '';

        if (! $appId || ! $config->merchant_id || ! $order->trade_no) {
            return [
                'state' => 'unknown',
                'channel_trade_no' => $order->channel_trade_no,
                'fail_reason' => '打款配置不完整，无法查询终态',
            ];
        }

        try {
            $pay = Pay::wechat($this->buildConfig($config, $appId));
            $result = $pay->query([
                '_action' => 'transfer',
                'out_batch_no' => $order->trade_no,
                'out_detail_no' => $order->trade_no,
            ]);
            $data = $this->toArray($result);

            $status = strtoupper((string) ($data['detail_status'] ?? $data['batch_status'] ?? ''));
            $channelTradeNo = $data['detail_id'] ?? $data['batch_id'] ?? $order->channel_trade_no;

            return match ($status) {
                'SUCCESS' => [
                    'state' => 'success',
                    'channel_trade_no' => $channelTradeNo !== null ? (string) $channelTradeNo : null,
                    'fail_reason' => null,
                ],
                'FAIL', 'CANCELLED', 'CANCELED' => [
                    'state' => 'failed',
                    'channel_trade_no' => $channelTradeNo !== null ? (string) $channelTradeNo : null,
                    'fail_reason' => (string) ($data['fail_reason'] ?? '微信转账失败'),
                ],
                default => [
                    'state' => 'processing',
                    'channel_trade_no' => $channelTradeNo !== null ? (string) $channelTradeNo : null,
                    'fail_reason' => null,
                ],
            };
        } catch (\Throwable $e) {
            Log::warning('WechatTransferDriver: query failed', [
                'trade_no' => $order->trade_no,
                'error' => $e->getMessage(),
            ]);

            return [
                'state' => 'unknown',
                'channel_trade_no' => $order->channel_trade_no,
                'fail_reason' => mb_substr($e->getMessage(), 0, 250),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $result): array
    {
        return method_exists($result, 'toArray') ? $result->toArray() : (array) $result;
    }

    /**
     * 构建 yansongda/pay 微信 V3 配置
     */
    protected function buildConfig(PayoutConfig $config, string $appId): array
    {
        $extra = $config->extra ?? [];

        return array_filter([
            'app_id' => $appId,
            'mch_id' => $config->merchant_id,
            'mch_secret_key_v3' => $config->apiv3_key,
            'mch_secret_cert' => $config->private_key,
            'mch_public_cert_path' => $extra['public_cert_path'] ?? null,
            'notify_url' => $extra['notify_url'] ?? null,
        ], fn ($v) => $v !== '' && $v !== null);
    }
}
