<?php
/**
 * 客户端真实 IP 解析配置
 *
 * webman 的 getRealIp() 在直连来自内网时会无条件取 x-forwarded-for 的第一个元素，
 * 而这个头由客户端自己填。基座按「从近到远」遍历代理链来还原原始客户端，
 * 遍历逻辑见 app/utils/ClientInfo::clientIp()。
 *
 * - trusted_proxies：可信反向代理名单，支持精确 IP 与 CIDR，逗号分隔取环境变量。
 *   例：TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8
 * - trust_intranet：直连来自内网时是否把内网地址一律当作可信代理。
 *   默认 true —— 兼容「本机 nginx 反代、没配名单」的既有部署；
 *   公网直连 80/443 或已配名单时建议设为 false，此时代理头完全不被信任。
 */
return [
    'trusted_proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        static fn (string $cidr): bool => $cidr !== ''
    )),
    'trust_intranet' => filter_var(env('TRUST_PROXY_INTRANET', 'true'), FILTER_VALIDATE_BOOLEAN),
];
