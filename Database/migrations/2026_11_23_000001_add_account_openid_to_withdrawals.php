<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Support\MySqlOnlyMigration;

/**
 * 提现单增加微信转账 openid（2026-11-23）。
 *
 * 微信商家转账必须携带收款用户 openid；此前 buildAccountInfo() 恒为 null，
 * 自动打款在驱动入口必然失败。openid 由分销员申请时提交，运营审核时也可覆盖。
 *
 * 存量库幂等：列已存在则跳过；只支持 MySQL（模块迁移整体口径）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        if (! Schema::hasTable('withdrawals') || Schema::hasColumn('withdrawals', 'account_openid')) {
            return;
        }

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('account_openid', 100)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        if (Schema::hasTable('withdrawals') && Schema::hasColumn('withdrawals', 'account_openid')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->dropColumn('account_openid');
            });
        }
    }
};
