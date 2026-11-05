<?php

namespace app\process;

use app\basic\TaskRegistry;
use support\Log;
use support\Redis;
use Workerman\Crontab\Crontab;
use Workerman\Worker;

/**
 * 插件定时任务调度进程
 *
 * 注册到 config/process.php（count=1，单进程调度），在 onWorkerStart 时
 * 读取 TaskRegistry 已注册任务并一次性注册到 workerman/crontab。
 *
 * 任务体执行前抢占 Redis 锁（SETNX + TTL），防止多进程/多实例重复执行；
 * 锁 TTL 固定 300s（保守 5 分钟覆盖常规 cron 间隔；插件自有幂等逻辑是主保障）。
 *
 * 进程启动依赖：全局 bootstrap（含 PluginBootstrapper）先于自定义进程 onWorkerStart
 * 执行（已核前提），因此 TaskRegistry 在 boot() 内注册的条目在本进程内已就绪。
 */
class PluginTask
{
    /** Redis 锁 key 前缀 */
    private const LOCK_PREFIX = 'atrman:task:lock:';

    /** 锁 TTL（秒）：保守 5 分钟覆盖常规 cron 间隔 */
    private const LOCK_TTL = 300;

    public static function onWorkerStart(Worker $worker): void
    {
        if (!self::enabled()) {
            return;
        }

        $count = 0;
        foreach (TaskRegistry::all() as $task) {
            try {
                new Crontab($task['cron'], function () use ($task) {
                    self::runWithLock($task);
                });
                $count++;
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[PluginTask] 任务 %s cron 表达式注册失败：%s',
                    $task['name'],
                    $e->getMessage()
                ));
            }
        }

        if ($count > 0) {
            Log::info(sprintf('[PluginTask] 已注册 %d 个插件定时任务', $count));
        }
    }

    /**
     * 抢占 Redis 锁执行任务（成功：执行 + 释放）；其它实例在锁存活期内跳过
     */
    private static function runWithLock(array $task): void
    {
        $lockKey = self::LOCK_PREFIX . $task['name'];
        // set 必须用位置参数 'EX', ttl, 'NX'（illuminate/redis 会把它翻译给 phpredis，predis 原生也支持）；
        // 传 phpredis 风格的选项数组会被 PhpRedisConnection::set 当数组键，PHP 8.x 直接抛 TypeError
        $locked = Redis::set($lockKey, 1, 'EX', self::LOCK_TTL, 'NX');
        if (!$locked) {
            // 锁已被抢占（其它 worker / 其它实例正在运行）
            return;
        }

        try {
            ($task['job'])();
        } catch (\Throwable $e) {
            Log::error(sprintf(
                '[PluginTask] 任务 %s 执行失败：%s',
                $task['name'],
                $e->getMessage()
            ));
        }
    }

    /** 特性开关（默认开启；task_enabled=false 时进程启动不注册任何任务，空转） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.task_enabled', true) : true;
    }
}
