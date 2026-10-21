<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Distribution\Drivers;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Distribution\Contracts\PayoutDriverContract;
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
 */
class WechatTransferDriver implements PayoutDriverContract
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
                'channel_trade_no' => null,
                'fail_reason' => '缺少收款用户 openid',
            ];
        }

        $extra = $config->extra ?? [];
        $appId = $extra['app_id'] ?? '';

        if (! $appId || ! $config->merchant_id) {
            return [
                'success' => false,
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
            $data = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;

            $batchId = $data['batch_id'] ?? null;
            if ($batchId) {
                return [
                    'success' => true,
                    'channel_trade_no' => (string) $batchId,
                    'fail_reason' => null,
                ];
            }

            return [
                'success' => false,
                'channel_trade_no' => null,
                'fail_reason' => '微信转账未返回 batch_id',
            ];
        } catch (\Throwable $e) {
            Log::error('WechatTransferDriver: transfer failed', [
                'trade_no' => $order->trade_no,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'channel_trade_no' => null,
                'fail_reason' => mb_substr($e->getMessage(), 0, 250),
            ];
        }
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
