<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Distribution\Http\Controllers\DistributionController;
use MultiTenantSaas\Modules\Distribution\Http\Controllers\DistributorCenterController;

/*
|--------------------------------------------------------------------------
| 分销路由（需登录）
|--------------------------------------------------------------------------
|
| 前缀由 DistributionServiceProvider::loadModuleRoutes() 从
| config('distribution.route_prefix') 读取（框架默认 api/v1；下游设
| DISTRIBUTION_ROUTE_PREFIX=api/v1/biz 沿用既有契约）。
|
| C 端分销员中心面向终端 User，不挂 RBAC —— RBAC 只作用于 Operator。
| B 端运营面按读写分离：列表/详情/配置读取 sales.view，
| 状态变更/规则维护/提现审核/配置写入 sales.manage。
|
*/

// ========== C 端分销员中心（终端用户 H5）==========
Route::prefix('distribution-center')->group(function () {
    Route::get('profile', [DistributorCenterController::class, 'profile']);
    Route::post('apply', [DistributorCenterController::class, 'apply']);
    Route::post('bind', [DistributorCenterController::class, 'bind']);
    Route::get('invite', [DistributorCenterController::class, 'invite']);
    Route::get('team', [DistributorCenterController::class, 'team']);
    Route::get('commissions', [DistributorCenterController::class, 'commissions']);
    Route::get('ledger', [DistributorCenterController::class, 'ledger']);
    Route::post('withdrawals', [DistributorCenterController::class, 'requestWithdrawal']);
    Route::get('withdrawals', [DistributorCenterController::class, 'withdrawals']);
});

// ========== B 端管理（租户运营者 Console）==========
Route::middleware('rbac.permission:sales.view')->group(function () {
    Route::get('distributors', [DistributionController::class, 'distributors']);
    Route::get('distributors/tree', [DistributionController::class, 'distributorTree']);
    Route::get('distributors/{id}', [DistributionController::class, 'distributorDetail']);
    Route::get('commission-rules', [DistributionController::class, 'commissionRules']);
    Route::get('withdrawals', [DistributionController::class, 'withdrawals']);
    Route::get('distribution-config', [DistributionController::class, 'getConfig']);
    Route::get('payout-config', [DistributionController::class, 'getPayoutConfig']);
});

Route::middleware('rbac.permission:sales.manage')->group(function () {
    Route::put('distributors/{id}/status', [DistributionController::class, 'updateDistributorStatus']);

    Route::post('commission-rules', [DistributionController::class, 'createCommissionRule']);
    Route::put('commission-rules/{id}', [DistributionController::class, 'updateCommissionRule']);
    Route::delete('commission-rules/{id}', [DistributionController::class, 'deleteCommissionRule']);
    Route::put('commission-rules/{id}/status', [DistributionController::class, 'toggleCommissionRule']);

    Route::put('withdrawals/{id}/approve', [DistributionController::class, 'approveWithdrawal']);
    Route::put('withdrawals/{id}/reject', [DistributionController::class, 'rejectWithdrawal']);
    Route::put('withdrawals/{id}/confirm-paid', [DistributionController::class, 'confirmWithdrawalPaid']);

    Route::put('distribution-config', [DistributionController::class, 'updateConfig']);
    Route::put('payout-config', [DistributionController::class, 'updatePayoutConfig']);
});
