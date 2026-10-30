<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Support\MySqlOnlyMigration;

return new class extends Migration
{
    public function up(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        // 提升归位前由 scrm 同名迁移建表；存量库按 basename 判重不会重跑，
        // 此守卫只防「半途失败后重试」的重复建表。
        if (Schema::hasTable('distributors')) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: distributors（分销员）
        DB::statement(<<<'SQL'
CREATE TABLE `distributors` (
  `distributor_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL COMMENT '关联用户(User)身份',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `level` int NOT NULL DEFAULT '1',
  `invite_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `total_commission` bigint unsigned NOT NULL DEFAULT '0' COMMENT '累计佣金（分）',
  `available_commission` bigint unsigned NOT NULL DEFAULT '0' COMMENT '可提现余额（分）',
  `frozen_commission` bigint unsigned NOT NULL DEFAULT '0' COMMENT '冻结佣金（分）',
  `subordinate_count` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `audit_remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`distributor_id`),
  KEY `distributors_tenant_status_index` (`tenant_id`, `status`),
  KEY `distributors_tenant_invite_index` (`tenant_id`, `invite_code`),
  KEY `distributors_parent_id_index` (`parent_id`),
  KEY `distributors_user_id_index` (`user_id`),
  CONSTRAINT `distributors_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: distribution_relations（客户-分销员归因关系）
        DB::statement(<<<'SQL'
CREATE TABLE `distribution_relations` (
  `relation_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL COMMENT '被归因客户(User)',
  `distributor_id` bigint unsigned NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`relation_id`),
  UNIQUE KEY `distribution_relations_tenant_user_unique` (`tenant_id`, `user_id`),
  KEY `distribution_relations_distributor_index` (`distributor_id`),
  CONSTRAINT `distribution_relations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: commission_rules（佣金规则）
        DB::statement(<<<'SQL'
CREATE TABLE `commission_rules` (
  `commission_rule_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` int NOT NULL DEFAULT '1',
  `calc_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'rate',
  `rate` decimal(6,2) NOT NULL DEFAULT '0.00',
  `fixed_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '固定佣金（分）',
  `min_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '佣金下限（分）',
  `max_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '佣金上限（分）',
  `apply_scope` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `scope_config` json DEFAULT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`commission_rule_id`),
  KEY `commission_rules_tenant_status_index` (`tenant_id`, `status`),
  KEY `commission_rules_tenant_level_index` (`tenant_id`, `level`),
  CONSTRAINT `commission_rules_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: commission_records（佣金流水）
        DB::statement(<<<'SQL'
CREATE TABLE `commission_records` (
  `commission_record_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `distributor_id` bigint unsigned NOT NULL,
  `order_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `order_no` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_user_id` bigint unsigned DEFAULT NULL COMMENT '买家用户(User)ID',
  `level` int NOT NULL DEFAULT '1',
  `order_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '订单金额（分）',
  `commission_amount` bigint NOT NULL DEFAULT '0' COMMENT '佣金额（分）',
  `rule_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `settled_at` timestamp NULL DEFAULT NULL,
  `cancel_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`commission_record_id`),
  UNIQUE KEY `commission_records_order_level_unique` (`order_type`, `order_id`, `level`, `distributor_id`),
  KEY `commission_records_tenant_distributor_index` (`tenant_id`, `distributor_id`),
  KEY `commission_records_status_index` (`status`),
  CONSTRAINT `commission_records_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: distributor_ledgers（账本流水）
        DB::statement(<<<'SQL'
CREATE TABLE `distributor_ledgers` (
  `ledger_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `distributor_id` bigint unsigned NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint NOT NULL DEFAULT '0' COMMENT '发生额（分，可为负）',
  `balance_after` bigint NOT NULL DEFAULT '0' COMMENT '变动后余额（分）',
  `related_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ledger_id`),
  KEY `distributor_ledgers_tenant_distributor_index` (`tenant_id`, `distributor_id`),
  CONSTRAINT `distributor_ledgers_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: withdrawals（提现单）
        DB::statement(<<<'SQL'
CREATE TABLE `withdrawals` (
  `withdrawal_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `distributor_id` bigint unsigned NOT NULL,
  `amount` bigint NOT NULL DEFAULT '0' COMMENT '发生额（分，可为负）',
  `fee` bigint unsigned NOT NULL DEFAULT '0' COMMENT '手续费（分）',
  `actual_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '实际到账（分）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `account_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audit_remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audited_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `payout_order_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`withdrawal_id`),
  KEY `withdrawals_tenant_distributor_index` (`tenant_id`, `distributor_id`),
  KEY `withdrawals_status_index` (`status`),
  CONSTRAINT `withdrawals_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: payout_configs（租户打款配置）
        DB::statement(<<<'SQL'
CREATE TABLE `payout_configs` (
  `payout_config_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `merchant_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `private_key` text COLLATE utf8mb4_unicode_ci,
  `apiv3_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra` json DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`payout_config_id`),
  UNIQUE KEY `payout_configs_tenant_unique` (`tenant_id`),
  CONSTRAINT `payout_configs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: payout_orders（打款单）
        DB::statement(<<<'SQL'
CREATE TABLE `payout_orders` (
  `payout_order_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `withdrawal_id` bigint unsigned NOT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint NOT NULL DEFAULT '0' COMMENT '发生额（分，可为负）',
  `account_info` json DEFAULT NULL,
  `trade_no` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel_trade_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fail_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`payout_order_id`),
  KEY `payout_orders_tenant_index` (`tenant_id`),
  KEY `payout_orders_withdrawal_index` (`withdrawal_id`),
  KEY `payout_orders_trade_no_index` (`trade_no`),
  CONSTRAINT `payout_orders_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: distribution_configs（租户分销全局配置）
        DB::statement(<<<'SQL'
CREATE TABLE `distribution_configs` (
  `distribution_config_id` bigint unsigned NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `max_level` int NOT NULL DEFAULT '2',
  `self_buy_rebate` tinyint(1) NOT NULL DEFAULT '0',
  `audit_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `settle_days` int NOT NULL DEFAULT '7',
  `lock_days` int NOT NULL DEFAULT '0',
  `withdraw_min_amount` bigint unsigned NOT NULL DEFAULT '0' COMMENT '最低提现额（分）',
  `withdraw_fee_rate` decimal(6,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`distribution_config_id`),
  UNIQUE KEY `distribution_configs_tenant_unique` (`tenant_id`),
  CONSTRAINT `distribution_configs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        Schema::dropIfExists('distribution_configs');
        Schema::dropIfExists('payout_orders');
        Schema::dropIfExists('payout_configs');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('distributor_ledgers');
        Schema::dropIfExists('commission_records');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('distribution_relations');
        Schema::dropIfExists('distributors');
    }
};
