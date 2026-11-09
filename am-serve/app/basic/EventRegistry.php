<?php

namespace app\basic;

use support\Log;

/**
 * 插件事件总线（带优先级 + owner 的轻量发布订阅）
 *
 * 与基座现有 Webman\Event\Event 互补、互不干扰：基座既有事件（user.loginLog 等）
 * 仍走 Webman Event；本注册表服务于插件订阅与标准业务事件
 *
 * 进程级静态注册：插件监听器一律在 boot() 内注册，或写入插件目录
 * config/event.php（格式同基座 config/event.php，由 PluginManager::bootPlugins 聚合，
 * owner 自动记为插件名）。
 */
class EventRegistry
{
    /** @var array<string, list<array{listener: callable|array, priority: int, owner: string, seq: int}>> */
    private static array $listeners = [];

    /** 注册序号（同优先级下保持注册先后顺序） */
    private static int $seq = 0;

    /**
     * 注册事件监听器
     *
     * @param string $event 事件名（域.动作，如 file.uploaded）
     * @param callable|array $listener 回调，或 [Class::class, 'method']（与基座 config/event.php 同构）
     * @param int $priority 优先级，越大越先执行，默认 0
     * @param string $owner 归属插件名（卸载/禁用对称清理用；基座注册留空）
     */
    public static function listen(string $event, callable|array $listener, int $priority = 0, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'listen');
        self::$listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /**
     * 触发事件（同步广播，监听器接收 payload 数组作为唯一参数）
     *
     * 单个监听器异常记日志隔离，不影响其它监听器与主流程；
     * 无监听或开关关闭时为空操作（零影响保证）。
     *
     * @param string $event 事件名
     * @param array $payload 事件载荷（只读广播，不回写主流程）
     * @return array 各监听器返回值按执行顺序组成的列表
     */
    public static function emit(string $event, array $payload = []): array
    {
        if (!self::enabled() || empty(self::$listeners[$event])) {
            return [];
        }

        $entries = self::$listeners[$event];
        usort($entries, fn($a, $b) => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);

        $results = [];
        foreach ($entries as $entry) {
            try {
                $results[] = call_user_func($entry['listener'], $payload);
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[EventRegistry] 事件 %s 监听器执行失败（owner=%s）：%s',
                    $event,
                    $entry['owner'] ?: '-',
                    $e->getMessage()
                ));
                $results[] = null;
            }
        }
        return $results;
    }

    /**
     * 判断事件是否已有监听器
     */
    public static function has(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * 按 owner 清理某插件的全部监听器（禁用/卸载时由 PluginManager 调用）
     */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        foreach (self::$listeners as $event => $entries) {
            $remaining = array_values(array_filter($entries, fn($entry) => $entry['owner'] !== $owner));
            if ($remaining) {
                self::$listeners[$event] = $remaining;
            } else {
                unset(self::$listeners[$event]);
            }
        }
    }

    /** 特性开关（config/plugin_extend.php；非 webman 环境视为开启，便于脚本校验） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.event_enabled', true) : true;
    }
}
