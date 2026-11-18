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

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

// Worker 进程数：1panel / 低规格容器里 cpu_count() 可能只有 1，乘 4 直接 OOM。
// 封顶 4：1 vCPU × 4 = 4 worker 抢单核会上下文切换，4 vCPU × 4 = 16 worker 也没意义。
// 要更多？改 .env 里的 WORKER_COUNT 或用 production.php 覆盖。
$workerCount = (int)env('WORKER_COUNT', 0);
if ($workerCount <= 0) {
    $workerCount = min(cpu_count(), 4);
}

// 文件监控显式开关：容器等只读镜像环境用 MONITOR_FILE_ENABLED=false 关闭，省掉 monitor 的轮询开销。
// 未配置时维持原口径：守护模式（-d）关闭、仅 Linux 开启（Windows 下 Monitor 的 reload 信号不可用）。
$monitorEnv = env('MONITOR_FILE_ENABLED');
$fileMonitorEnabled = $monitorEnv === null
    ? (!in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/')
    : filter_var((string)$monitorEnv, FILTER_VALIDATE_BOOLEAN);

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => $workerCount,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => $fileMonitorEnabled,
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
    // 插件定时任务调度进程（单进程，任务体 Redis 锁兜底）
    'plugin_task' => [
        'handler' => app\process\PluginTask::class,
        'count' => 1,
    ]
];
