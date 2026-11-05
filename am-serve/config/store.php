<?php

/**
 * 应用商店配置
 */
return [
    // 数据源提供者：mock（本地模拟）/ remote（远程应用市场API）
    'provider' => env('STORE_PROVIDER', 'remote'),

    // 远程应用市场 API 地址（provider=remote 时使用）
    'remote_url' => env('STORE_REMOTE_URL', 'https://apps.atrman.com'),

    // 远程请求超时时间（秒）
    'remote_timeout' => (int)env('STORE_REMOTE_TIMEOUT', 30),

    // 安装包下载超时时间（秒）
    'download_timeout' => (int)env('STORE_DOWNLOAD_TIMEOUT', 300),

    // 基座更新检测与在线升级总开关（读取一律走 StoreService::systemUpdateEnabled()）
    'system_update_enabled' => (bool)env('STORE_SYSTEM_UPDATE', true),

    // 付费应用包签名 / License 验签配置
    'signature' => [
        // 签名算法：ed25519（需 sodium 扩展）/ rsa-sha256
        'alg' => env('STORE_SIGN_ALG', 'ed25519'),
        // 市场官方验签公钥（Ed25519 为 base64，RSA 为 PEM 公钥内容）
        // mock 模式无需配置（自动使用 runtime/mock/market_keys.json 的开发公钥）
        'public_key' => env('STORE_SIGN_PUBLIC_KEY', 'jo38imKF+SJB1rHwxBh2ItTCJPaChMA3lGgW+601IaU='),
    ],

    // 发布清单（签名的唯一载体：市场签一份「哪些版本、各自 sha256」的清单，包字节不被改动）
    // 详见 RELEASE_MANIFEST_DESIGN.md
    'release_manifest' => [
        // strict=以清单为准（默认）/ shadow=清单校验只记日志、不参与判定 / off=完整性校验整体关闭
        // 包内签验签已删除，后两档没有第二层兜底，只作为线上排障期间的临时阀门（默认 strict，改一次都要留痕）
        'mode' => env('STORE_RELEASE_MANIFEST_MODE', 'strict'),
    ],

    // 付费应用 License 心跳
    'heartbeat' => [
        // 心跳间隔（秒），默认 7 天
        'interval' => (int)env('STORE_HEARTBEAT_INTERVAL', 604800),
        // 无法连接市场时的离线宽限期（天），超期锁定插件
        'grace_days' => (int)env('STORE_HEARTBEAT_GRACE_DAYS', 60),
    ],
];