<?php

namespace app\service\system;

use app\service\plugin\PluginManifestService;
use app\model\system\ErrorReport;
use support\Log;

/**
 * 前端错误上报收口服务。
 *
 * 安全口径：
 * - 公开端点投递，入库前来源标识按应用命名规范校验 + 长度截断 + 敏感片段脱敏，任何畸形输入都不得写库报错；
 * - 只存不回显：不提供详情查询接口，stats() 只输出聚合计数供系统信息页展示；
 * - 存储失败仅 warning 日志，不向调用方抛错——上报接口自身报错会在前端触发新的错误上报，形成放大回路；
 * - 频控由控制器层 LoginThrottleService::assertIpRateLimited 承担（Redis 故障 fail-open）。
 */
class ErrorReportService
{
    private const ALLOWED_TYPES = ['vue', 'script', 'promise', 'resource'];

    private const ALLOWED_LEVELS = ['error', 'warn'];

    /** 来源标识长度上限，与 am_system_error_report.app 的 varchar(50) 列宽对齐 */
    private const MAX_APP = 50;

    private const MAX_MESSAGE = 1000;
    private const MAX_STACK = 4000;
    private const MAX_URL = 500;
    private const MAX_UA = 255;

    /**
     * 校验、脱敏并落库一条前端错误。任何异常都吞掉（记 warning），保证端点恒成功。
     */
    public static function report(array $payload, string $ip, ?int $userId = null): void
    {
        try {
            $app = self::normalizeApp((string)($payload['app'] ?? ''));
            $type = in_array($payload['type'] ?? '', self::ALLOWED_TYPES, true) ? (string)$payload['type'] : '';
            $level = in_array($payload['level'] ?? 'error', self::ALLOWED_LEVELS, true) ? (string)$payload['level'] : 'error';

            $message = self::scrub((string)($payload['message'] ?? ''));
            if ($message === '') {
                // 空消息没有排查价值，直接丢弃，防匿名端点被空写刷表
                return;
            }

            ErrorReport::create([
                'app' => $app,
                'level' => $level,
                'type' => $type,
                'message' => self::truncate($message, self::MAX_MESSAGE),
                'stack' => self::truncate(self::scrub((string)($payload['stack'] ?? '')), self::MAX_STACK),
                'page_url' => self::truncate(self::scrub((string)($payload['url'] ?? '')), self::MAX_URL),
                'user_agent' => self::truncate((string)($payload['user_agent'] ?? ''), self::MAX_UA),
                'ip' => self::truncate($ip, 45),
                'user_id' => ($userId !== null && $userId > 0) ? $userId : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[错误上报] 落库失败（不影响前端）：' . $e->getMessage());
        }
    }

    /**
     * 聚合统计（系统信息页面板）：近 1 小时 / 近 24 小时 / 总量，及 24 小时内按应用分组计数。
     */
    public static function stats(): array
    {
        try {
            $now = time();
            return [
                'last_1h' => ErrorReport::where('create_time', '>=', $now - 3600)->count(),
                'last_24h' => ErrorReport::where('create_time', '>=', $now - 86400)->count(),
                'total' => ErrorReport::count(),
                'by_app_24h' => ErrorReport::where('create_time', '>=', $now - 86400)
                    ->field('app, count(*) as cnt')
                    ->group('app')
                    ->select()
                    ->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[错误上报] 聚合统计失败（降级空值）：' . $e->getMessage());
            return ['last_1h' => 0, 'last_24h' => 0, 'total' => 0, 'by_app_24h' => []];
        }
    }

    /**
     * 脱敏：Bearer 令牌、URL 查询串中的令牌/口令/密钥类参数一律打码。
     */
    private static function scrub(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1***', $text) ?? $text;
        $text = preg_replace(
            '/([?&](?:token|access_token|refresh_token|authorization|password|passwd|secret|api_key|apikey|key)=)[^&#]+/i',
            '$1***',
            $text
        ) ?? $text;
        return $text;
    }

    private static function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * 来源应用标识归一化：基座自带应用与第三方插件共用 am_ 命名规范（PluginManifestService::NAME_PATTERN），
     * 只校验格式、不锁枚举，新插件无需改动基座即可纳入错误统计；非法/空值归空串（前端显示「未知来源」）。
     */
    private static function normalizeApp(string $app): string
    {
        $app = self::truncate(trim($app), self::MAX_APP);
        return preg_match(PluginManifestService::NAME_PATTERN, $app) === 1 ? $app : '';
    }
}
