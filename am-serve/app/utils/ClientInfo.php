<?php

namespace app\utils;

class ClientInfo
{
    /**
     * 获取 IP 地理位置
     */
    public static function ipLocation(string $ip): string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '未知';
        }

        if (self::isPrivateIp($ip)) {
            return '内网IP';
        }

        try {
            $ip2region = new \Ip2Region();
            $region = $ip2region->memorySearch($ip);
        } catch (\Throwable $e) {
            return '未知';
        }

        $regionText = $region['region'] ?? '';
        if ($regionText === '') {
            return '未知';
        }

        [$country, $province, $city, $network] = array_pad(explode('|', $regionText), 4, '');

        if ($network === '内网IP') {
            return $network;
        }

        if ($country === '中国') {
            return trim($province . '-' . $city . ':' . $network, '-:');
        }

        if ($country === '0' || $country === '') {
            return '未知';
        }

        return $country;
    }

    /**
     * 获取浏览器信息
     */
    public static function browser(string $userAgent): string
    {
        if (preg_match('/MSIE|Trident/i', $userAgent)) {
            return 'IE';
        }

        if (preg_match('/Edg/i', $userAgent)) {
            return 'Edge';
        }

        if (preg_match('/Firefox/i', $userAgent)) {
            return 'Firefox';
        }

        if (preg_match('/Chrome|CriOS/i', $userAgent)) {
            return 'Chrome';
        }

        if (preg_match('/Safari/i', $userAgent)) {
            return 'Safari';
        }

        if (preg_match('/Opera|OPR/i', $userAgent)) {
            return 'Opera';
        }

        return 'Other';
    }

    /**
     * 获取操作系统信息
     */
    public static function os(string $userAgent): string
    {
        if (preg_match('/windows|win32|win64/i', $userAgent)) {
            return 'Win';
        }

        if (preg_match('/macintosh|mac os x/i', $userAgent)) {
            return 'Mac';
        }

        if (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            return 'iOS';
        }

        if (preg_match('/android/i', $userAgent)) {
            return 'Android';
        }

        if (preg_match('/linux/i', $userAgent)) {
            return 'Linux';
        }

        return 'Other';
    }

    /**
     * 获取客户端信息
     */
    public static function fromRequest(object $request): array
    {
        $ip = self::clientIp($request);
        $userAgent = method_exists($request, 'header') ? (string) $request->header('user-agent', '') : '';

        return [
            'ip' => $ip,
            'ip_location' => self::ipLocation($ip),
            'os' => self::os($userAgent),
            'browser' => self::browser($userAgent),
        ];
    }

    /**
     * 解析真实客户端 IP。
     *
     * 框架的 getRealIp() 在直连来自内网时（本机 nginx 反代的典型形态）无条件取
     * x-forwarded-for 的第一个元素，而该元素由客户端自行伪造——审计日志与登录限流
     * 因此可以按任意 IP 分散。这里按「从近到远」遍历代理链，跳过可信代理，取第一个
     * 不可信的地址；名单见 config/client_ip.php。
     */
    public static function clientIp(object $request): string
    {
        $fallback = method_exists($request, 'getRealIp') ? (string) $request->getRealIp() : '';
        if (!method_exists($request, 'getRemoteIp')) {
            return $fallback;
        }

        $remoteIp = (string) $request->getRemoteIp();
        $header = (string) $request->header('x-forwarded-for');
        if ($header === '') {
            $header = (string) $request->header('x-real-ip');
        }

        // 由近及远：直连地址在最前，XFF 从右往左补在后面
        $chain = [$remoteIp];
        foreach (array_reverse(array_map('trim', explode(',', $header))) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $chain[] = $candidate;
            }
        }
        foreach ($chain as $candidate) {
            if (!self::isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        // 整条链都可信（纯内网/全代理）：取最左边的原始客户端地址
        return end($chain) ?: $remoteIp;
    }

    /**
     * 该来源地址的代理头是否可信：命中可信名单，或在 trust_intranet 下属于内网。
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        /** @var array<string> $trusted */
        $trusted = (array) config('client_ip.trusted_proxies', []);
        foreach ($trusted as $cidr) {
            if (self::ipInCidr($ip, (string) $cidr)) {
                return true;
            }
        }

        return (bool) config('client_ip.trust_intranet', true) && self::isPrivateIp($ip);
    }

    /** 支持 `10.0.0.0/8` 与精确 IP 两种写法；写法非法时按不匹配处理。 */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$network, $bits] = explode('/', $cidr, 2);
        if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            return false;
        }
        $ipBytes = inet_pton($ip);
        $netBytes = inet_pton($network);
        if ($ipBytes === false || $netBytes === false || strlen($ipBytes) !== strlen($netBytes)) {
            return false;
        }
        $bits = (int) $bits;
        $total = strlen($ipBytes) * 8;
        if ($bits < 0 || $bits > $total) {
            return false;
        }
        for ($index = 0; $index < strlen($ipBytes); $index++) {
            if ($bits <= 0) {
                return true;
            }
            if ($bits >= 8) {
                if ($ipBytes[$index] !== $netBytes[$index]) {
                    return false;
                }
                $bits -= 8;
                continue;
            }
            $mask = 256 - (1 << (8 - $bits));
            return (ord($ipBytes[$index]) & $mask) === (ord($netBytes[$index]) & $mask);
        }

        return true;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
