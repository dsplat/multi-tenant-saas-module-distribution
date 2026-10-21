<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（配置插口）
    |--------------------------------------------------------------------------
    | 框架默认 'api/v1'；下游（如 scrm）设 DISTRIBUTION_ROUTE_PREFIX=api/v1/biz
    | 即保持既有路由契约零变更。覆写见 DistributionServiceProvider::loadModuleRoutes()。
    */
    'route_prefix' => env('DISTRIBUTION_ROUTE_PREFIX', 'api/v1'),

];
