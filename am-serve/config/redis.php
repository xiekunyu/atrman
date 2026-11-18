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
    'default' => [
        'password' => env('REDIS_PASSWORD', ''),
        'host' => env('REDIS_HOST', ''),
        'port' =>  env('REDIS_PORT', 6379),
        'database' => 0,
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ],
    // 队列只读连接：指向 redis-queue 的独立逻辑库（REDIS_QUEUE_DB，默认 1），
    // 供可观测性面板 LLEN/ZCARD 队列积压，不参与任何写入
    'queue' => [
        'password' => env('REDIS_PASSWORD', ''),
        'host' => env('REDIS_HOST', ''),
        'port' => env('REDIS_PORT', 6379),
        'database' => (int)env('REDIS_QUEUE_DB', 1),
        'pool' => [
            'max_connections' => 2,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ]
];
