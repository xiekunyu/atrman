<?php

/**
 * 跨域放行配置。
 *
 * 基座接口默认不对外开放跨域：只有下列三类来源才会拿到 Access-Control-Allow-Origin
 * （以及随之而来的 Access-Control-Allow-Credentials: true）：
 *  1. allow_origins 精确命中的来源（生产环境的独立前端域名写这里）；
 *  2. allow_local 为真时的本机来源（http(s)://localhost|127.0.0.1|[::1]，可带任意端口）；
 *  3. app.debug 为真时的任意来源（开发态兼容旧行为，生产务必 APP_DEBUG=false）。
 *
 * 同源部署（后台、插件应用与基座同域）不会发送 Origin，本配置对其无任何影响。
 */

$toList = static function (mixed $raw): array {
    $items = is_array($raw) ? $raw : explode(',', (string) $raw);
    return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $items), static fn($item) => $item !== ''));
};

return [
    // 例：CORS_ALLOW_ORIGINS = https://admin.example.com,https://app.example.com
    'allow_origins' => $toList(env('CORS_ALLOW_ORIGINS', '')),

    // 关掉可彻底拒绝本机来源（生产环境建议 CORS_ALLOW_LOCAL = false）
    'allow_local' => filter_var(env('CORS_ALLOW_LOCAL', true), FILTER_VALIDATE_BOOLEAN),
];
