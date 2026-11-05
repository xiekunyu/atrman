<?php

return [
    'default' => env('DB_TYPE', 'mysql'),
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type' => 'mysql',
            // 服务器地址
            'hostname' => env('DB_HOSTNAME', ''),
            // 数据库名
            'database' => env('DB_DATABASE', ''),
            // 数据库用户名
            'username' => env('DB_USERNAME', ''),
            // 数据库密码
            'password' => env('DB_PASSWORD', ''),
            // 数据库连接端口
            'hostport' => env('DB_PORT', '3306'),
            // 数据库连接参数
            'params' => [
                // 连接超时3秒
                \PDO::ATTR_TIMEOUT => 3,
            ],
            // 数据库编码默认采用utf8
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            // 数据库表前缀
            'prefix' => env('DB_PREFIX', 'am_'),
            
            // 断线重连
            'break_reconnect' => true,
            // 连接池配置
            'pool' => [
                'max_connections' => 5, // 最大连接数
                'min_connections' => 1, // 最小连接数
                'wait_timeout' => 3,    // 从连接池获取连接等待超时时间
                'idle_timeout' => 60,   // 连接最大空闲时间，超过该时间会被回收
                'heartbeat_interval' => 50, // 心跳检测间隔，需要小于60秒
            ],
        ],
    ],
    // 自定义分页类
    'paginator' =>  '',
];
