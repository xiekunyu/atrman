<?php
/**
 * redis-queue 连接配置：全部走 .env，不再硬编码本机地址。
 *
 * 队列使用独立逻辑库 REDIS_QUEUE_DB（默认 1），与缓存库（config/redis.php 默认 db0）
 * 隔离：清缓存（flushdb / 键淘汰误伤）不会带走队列消息与延迟集合。
 * 连接格式沿用 webman/redis-queue 约定：host 为 redis:// URL，鉴权与库放在 options。
 */
$redisHost = (string)env('REDIS_HOST', '127.0.0.1');
$redisPort = (int)env('REDIS_PORT', 6379);
$redisPassword = trim((string)env('REDIS_PASSWORD', ''));

return [
    'default' => [
        'host' => 'redis://' . $redisHost . ':' . ($redisPort > 0 ? $redisPort : 6379),
        'options' => [
            'auth' => $redisPassword !== '' ? $redisPassword : null,
            'db' => (int)env('REDIS_QUEUE_DB', 1),
            'prefix' => '',
            'max_attempts'  => 5,
            'retry_seconds' => 5,
        ],
        // Connection pool, supports only Swoole or Swow drivers.
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ]
    ],
];
