<?php

namespace app\basic;

/**
 * 插件定时任务注册中心
 *
 * 维护插件声明的定时任务清单，由 app\process\PluginTask 自定义进程在
 * onWorkerStart 时一次性注册到 workerman/crontab。任务体自带 Redis 锁
 * 防多进程重复执行（双保险：PluginTask 单进程调度 + Redis SETNX 兜底）。
 *
 * 进程级静态注册：插件 schedule() 一律写在 boot()，并传 owner=插件名。
 */
class TaskRegistry
{
    /** @var list<array{name: string, cron: string, job: callable, owner: string, seq: int}> */
    private static array $tasks = [];

    private static int $seq = 0;

    /**
     * 注册定时任务
     *
     * @param string $name 任务名（建议 {plugin}_{action} 前缀，避免跨插件冲突）
     * @param string $cron 标准 5 段 cron 表达式
     * @param callable $job 任务体（无参；须幂等）
     * @param string $owner 归属插件名
     */
    public static function schedule(string $name, string $cron, callable $job, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'schedule');
        self::$tasks[] = [
            'name' => $name,
            'cron' => $cron,
            'job' => $job,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /** 获取全部已注册任务（PluginTask 进程启动时读取） */
    public static function all(): array
    {
        return self::$tasks;
    }

    /** 是否已注册某任务 */
    public static function has(string $name): bool
    {
        return (bool)array_filter(self::$tasks, fn($t) => $t['name'] === $name);
    }

    /** 按 owner 清理某插件的全部任务 */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        self::$tasks = array_values(array_filter(
            self::$tasks,
            fn($t) => $t['owner'] !== $owner
        ));
    }
}
