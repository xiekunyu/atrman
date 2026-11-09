<?php

namespace app\service;

use app\basic\RegistryGuard;
use support\Log;
use Webman\RedisQueue\Client as RedisQueueClient;

/**
 * 插件统一队列门面
 *
 * 因 webman/redis-queue 一个消费者类只能绑定一个队列且扫描目录写死为 app/queue/redis，
 * 插件目录的 Consumer 不会被自动扫描。采用"单一信封队列 plugin_job"模式：基座只提供一个
 * 被扫描的消费者 PluginJobConsumer，所有插件任务都投递到 plugin_job，消息体携带目标
 * handler 名，消费时按名转发到 QueueService 注册的回调。
 *
 * 插件永远不写消费者类，无需重启即可新增 handler。
 */
class QueueService
{
    /** @var array<string, array{handler: callable, owner: string}> */
    private static array $handlers = [];

    /**
     * 投递任务到 plugin_job 信封队列
     *
     * @param string $handler handler 名（命名约定 {plugin}_{action}，如 am_demo_order_mail）
     * @param array $payload 业务载荷（必须可 json_encode）
     * @param int $delay 延迟秒数
     * @param string $dedupKey 幂等去重键（空串表示不去重）
     * @return bool 投递成功返回 true；队列不可用仅记日志不阻断（返回 false）
     */
    public static function dispatch(string $handler, array $payload, int $delay = 0, string $dedupKey = ''): bool
    {
        if (!self::enabled()) {
            return false;
        }
        try {
            RedisQueueClient::send('plugin_job', [
                'handler' => $handler,
                'payload' => $payload,
                'dedup' => $dedupKey,
            ], $delay);
            return true;
        } catch (\Throwable $e) {
            // 队列不可用仅记日志不阻断（尽力而为型 API，调用方按返回值降级）
            Log::warning('[QueueService] 投递 plugin_job 失败：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 注册 handler（插件在 boot() 内调用）
     *
     * @param string $handler handler 名
     * @param callable $jobHandler 处理回调 fn(array $payload): void；须幂等
     * @param string $owner 归属插件名
     */
    public static function consume(string $handler, callable $jobHandler, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'consume');
        // 基座注册的 handler（owner 空串）不允许被插件改判：信封队列按名转发，
        // 后启用的插件同名注册等于把别人的异步任务接到自己代码里执行。
        if ($owner !== '' && isset(self::$handlers[$handler]) && (self::$handlers[$handler]['owner'] ?? '') === '') {
            Log::warning("[QueueService] handler {$handler} 由基座注册，插件「{$owner}」的改判注册已忽略");
            return;
        }
        self::$handlers[$handler] = ['handler' => $jobHandler, 'owner' => $owner];
    }

    /** 按名查找 handler（PluginJobConsumer 消费时调用） */
    public static function resolve(string $handler): ?callable
    {
        return self::$handlers[$handler]['handler'] ?? null;
    }

    /** 是否已注册某 handler */
    public static function has(string $handler): bool
    {
        return isset(self::$handlers[$handler]);
    }

    /** 按 owner 清理某插件的全部 handler */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        self::$handlers = array_filter(self::$handlers, fn($h) => $h['owner'] !== $owner);
    }

    /** 特性开关（默认开启；无投递时无消费） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.queue_enabled', true) : true;
    }
}
