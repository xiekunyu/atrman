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
     * 抢占 Redis 锁执行任务（成功：执行 + finally 主动释放）；其它实例在锁存活期内跳过。
     *
     * 锁值用随机令牌：释放时 Lua 先比对再删，避免「本实例任务超时 → TTL 到期 →
     * 另一实例抢到同名锁 → 本实例 finally 误删别人的锁」。释放失败仍有 300s TTL 兜底。
     */
    private static function runWithLock(array $task): void
    {
        $lockKey = self::LOCK_PREFIX . $task['name'];
        $token = bin2hex(random_bytes(16));
        // set 必须用位置参数 'EX', ttl, 'NX'（illuminate/redis 会把它翻译给 phpredis，predis 原生也支持）；
        // 传 phpredis 风格的选项数组会被 PhpRedisConnection::set 当数组键，PHP 8.x 直接抛 TypeError
        $locked = Redis::set($lockKey, $token, 'EX', self::LOCK_TTL, 'NX');
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
        } finally {
            try {
                // KEYS[1]=锁键 ARGV[1]=本实例令牌；仅持有者可删，原子 compare-and-delete
                Redis::eval(
                    'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
                    1,
                    $lockKey,
                    $token
                );
            } catch (\Throwable $e) {
                // 主动释放失败不影响任务结果，交给 LOCK_TTL 到期自动释放
                Log::warning(sprintf(
                    '[PluginTask] 任务 %s 锁主动释放失败，等待 %ds TTL 到期：%s',
                    $task['name'],
                    self::LOCK_TTL,
                    $e->getMessage()
                ));
            }
        }
    }

    /** 特性开关（默认开启；task_enabled=false 时进程启动不注册任何任务，空转） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.task_enabled', true) : true;
    }
}
