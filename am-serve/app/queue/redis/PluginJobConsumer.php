<?php

namespace app\queue\redis;

use app\service\QueueService;
use support\Log;
use support\Redis;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 插件信封队列消费者
 *
 * 基座唯一被 webman/redis-queue 扫描到的插件任务消费者：所有插件任务都投递到
 * plugin_job 队列，本消费者拆信封、按 handler 名转发给 QueueService 注册的回调。
 *
 * 失败/重试语义完全照搬 SendNotifyJob：
 * - 参数非法（缺 handler）→ 丢弃不重试；
 * - 命中去重键 → 跳过；
 * - handler 未注册（插件已禁用）→ 记日志丢弃；
 * - 业务异常 → 清理去重键并抛出，交给队列重试机制；
 * - 重试耗尽 → onConsumeFailure 记日志。
 */
class PluginJobConsumer implements Consumer
{
    /** 消费的队列名（与 QueueService::dispatch 一致） */
    public $queue = 'plugin_job';

    /** Redis 连接名 */
    public $connection = 'default';

    /** 去重键 TTL（秒） */
    private const DEDUP_TTL = 86400;

    public function consume($data): void
    {
        $handler = $data['handler'] ?? '';
        $payload = $data['payload'] ?? [];
        $dedup = $data['dedup'] ?? '';

        // 参数非法属于永久性错误，记录后丢弃
        if (empty($handler) || !is_array($payload)) {
            Log::warning('[PluginJobConsumer] 任务参数非法，已丢弃', ['data' => $data]);
            return;
        }

        // 幂等：同一 dedup 在有效期内只处理一次
        if ($dedup !== '' && !self::acquireDedup($dedup)) {
            Log::info('[PluginJobConsumer] 命中去重键，跳过重复执行', ['dedup' => $dedup]);
            return;
        }

        $jobHandler = QueueService::resolve($handler);
        if ($jobHandler === null) {
            // handler 未注册（插件已禁用 / 进程尚未重启装配）→ 清理去重键并丢弃
            if ($dedup !== '') {
                Redis::del(self::dedupKey($dedup));
            }
            Log::warning("[PluginJobConsumer] handler $handler 未注册，已丢弃");
            return;
        }

        try {
            $jobHandler($payload);
            Log::info("[PluginJobConsumer] handler $handler 执行完成", ['dedup' => $dedup]);
        } catch (Throwable $e) {
            // 基础设施/业务异常：清理去重键并抛出，交给队列重试
            if ($dedup !== '') {
                Redis::del(self::dedupKey($dedup));
            }
            Log::error("[PluginJobConsumer] handler $handler 执行失败，将重试：" . $e->getMessage());
            throw $e;
        }
    }

    /** 重试次数耗尽后的最终失败处理 */
    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('[PluginJobConsumer] 重试耗尽，进入失败队列', [
            'package' => $package,
            'error' => $e->getMessage(),
        ]);
    }

    /** 抢占去重键（SETNX + TTL） */
    private static function acquireDedup(string $dedupKey): bool
    {
        // 同 SendNotifyJob：set 的 NX/EX 必须用位置参数，选项数组形式在 PHP 8.x 会抛 TypeError
        $ok = Redis::set(self::dedupKey($dedupKey), 1, 'EX', self::DEDUP_TTL, 'NX');
        return (bool)$ok;
    }

    private static function dedupKey(string $dedupKey): string
    {
        return 'atrman:plugin_job:dedup:' . $dedupKey;
    }
}
