<?php

namespace app\basic;

use support\Log;

/**
 * 推送私有频道鉴权注册中心
 *
 * 插件按频道模式注册鉴权回调，webman/push 私有频道订阅时（push/route.php）
 * 由本注册表裁决当前 sid 是否有权监听该频道。
 *
 * 模式匹配：fnmatch（* ? 通配），如 'private-sid_*'、'private-am_im_room_*'。
 * 多个模式命中时按注册先后，首个返回非 null 的回调裁决；全返回 null 走默认策略。
 *
 * 默认策略（无匹配鉴权器）：
 * - private-sid_{sid} 私有频道：仅 sid 本人可订阅（默认拒非本人）；
 * - 其余频道（含公共频道）：放行（与基座原 route.php 放行行为一致，零影响）。
 */
class ChannelAuthRegistry
{
    /** @var list<array{pattern: string, checker: callable, owner: string, seq: int}> */
    private static array $checkers = [];

    private static int $seq = 0;

    /** 系统私有用户频道前缀 */
    public const PRIVATE_USER_PREFIX = 'private-sid_';

    /**
     * 注册频道鉴权器
     *
     * @param string $pattern 频道模式（fnmatch 通配，如 'private-sid_*'）
     * @param callable $checker fn(string $sid, string $channel): ?bool；true 允许、false 拒绝、null 不裁决交给下一个
     * @param string $owner 归属插件名
     */
    public static function authorize(string $pattern, callable $checker, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'authorize');
        // 系统私有用户频道命名空间保留：check() 按注册先后取首个非 null 裁决，
        // 插件若注册 'private-sid_*'（或 '*'）并恒返回 true，等于替所有用户放行他人频道。
        // 需要自建私有频道的插件请用自己的前缀，如 'private-am_im_room_*'。
        if ($owner !== '' && fnmatch($pattern, self::PRIVATE_USER_PREFIX . 'sample')) {
            Log::warning("[ChannelAuthRegistry] 模式 {$pattern} 会截获系统私有用户频道，插件「{$owner}」的鉴权器注册已忽略");
            return;
        }

        self::$checkers[] = [
            'pattern' => $pattern,
            'checker' => $checker,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /**
     * 裁决当前 sid 是否可订阅频道
     */
    public static function check(string $sid, string $channel): bool
    {
        foreach (self::$checkers as $entry) {
            if (!fnmatch($entry['pattern'], $channel)) {
                continue;
            }
            $result = ($entry['checker'])($sid, $channel);
            if ($result !== null) {
                return (bool)$result;
            }
        }

        // 默认策略：private-sid_{sid} 仅本人；其它频道放行（基座原行为）
        if (str_starts_with($channel, self::PRIVATE_USER_PREFIX)) {
            return substr($channel, strlen(self::PRIVATE_USER_PREFIX)) === $sid;
        }
        return true;
    }

    /** 是否已有注册的鉴权器（诊断用） */
    public static function hasAny(): bool
    {
        return self::$checkers !== [];
    }

    /** 按 owner 清理某插件的鉴权器 */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        self::$checkers = array_values(array_filter(
            self::$checkers,
            fn($entry) => $entry['owner'] !== $owner
        ));
    }
}
