<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Support\MySqlOnlyMigration;

/**
 * 打款单对账调度（2026-11-23）。
 *
 * 对账命令不再每轮固定取最早 100 笔：每笔处理中的打款单持久化
 * next_reconcile_at 与已查询次数，命令只取到期单，查询后按指数退避重排，
 * 避免头部订单长期无终态时把后面的订单饿死。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        if (! Schema::hasTable('payout_orders') || Schema::hasColumn('payout_orders', 'next_reconcile_at')) {
            return;
        }

        Schema::table('payout_orders', function (Blueprint $table) {
            $table->unsignedInteger('reconcile_attempts')->default(0)->after('status');
            $table->timestamp('next_reconcile_at')->nullable()->after('reconcile_attempts');
            $table->index('next_reconcile_at', 'payout_orders_next_reconcile_index');
        });
    }

    public function down(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        if (Schema::hasTable('payout_orders') && Schema::hasColumn('payout_orders', 'next_reconcile_at')) {
            Schema::table('payout_orders', function (Blueprint $table) {
                $table->dropIndex('payout_orders_next_reconcile_index');
                $table->dropColumn(['reconcile_attempts', 'next_reconcile_at']);
            });
        }
    }
};
