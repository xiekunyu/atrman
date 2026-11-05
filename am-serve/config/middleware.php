<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    '@' => [
        app\middleware\InstallGuard::class,
        // 主程序升级维护门（升级任务执行期间拦截业务请求）
        app\middleware\SystemMaintenance::class,
        app\middleware\PluginStatusCheck::class,
        app\middleware\AuthCheck::class,
        app\middleware\OperateLog::class,
        app\middleware\ValidateCheck::class,
        app\middleware\Cors::class,
        // 安全响应头（X-Frame-Options / nosniff / Referrer-Policy）
        app\middleware\SecurityHeaders::class,
        // 插件中间件统一调度入口（无注册时纯透传）
        app\middleware\PluginMiddleware::class,
    ]
];