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
    'event_loop' => '',
    'stop_timeout' => 2,
    'pid_file' => runtime_path() . '/webman.pid',
    'status_file' => runtime_path() . '/webman.status',
    // 容器环境经 env 重定向到标准流，交 Docker 日志驱动轮转（docker logs 可直接查看）；
    // 裸机默认落 runtime/logs，由 deploy.md 的 logrotate 片段按日切割。
    'stdout_file' => env('WORKERMAN_STDOUT_FILE', runtime_path() . '/logs/stdout.log'),
    'log_file' => env('WORKERMAN_LOG_FILE', runtime_path() . '/logs/workerman.log'),
    'max_package_size' => 50 * 1024 * 1024
];
