<?php

namespace app\service\system;

use support\Log;
use support\Redis;

/**
 * 可观测性聚合：队列积压与前端错误计数，供系统信息页两个面板使用。
 *
 * 只读数、不加重型依赖：队列指标直查队列 Redis 逻辑库（REDIS_QUEUE_DB），
 * 错误计数来自 am_system_error_report 聚合；单项取数失败降级为空值，不阻断整页。
 *
 * 键名约定来自 workerman/redis-queue：
 * - 待消费列表：{redis-queue}-waiting{队列名}（LIST，LLEN 即积压条数）
 * - 延迟/失败集合：{redis-queue}-delayed、{redis-queue}-failed（全局 ZSET）
 */
class ObservabilityService
{
    /** 监控的队列名，与 app/queue/redis 下各 Consumer 的 $queue 对齐 */
    private const QUEUES = ['plugin_job', 'send_notify', 'license_heartbeat'];

    private const WAITING_PREFIX = '{redis-queue}-waiting';

    private const DELAYED_KEY = '{redis-queue}-delayed';

    private const FAILED_KEY = '{redis-queue}-failed';

    /**
     * Job 失败/重试累计计数器键前缀（写 queue 逻辑库，累计型不设 TTL）。
     * 完整键：前缀.{queue}:{result}，result=fail|retry；MetricsController 按同口径读取。
     */
    public const JOB_COUNTER_PREFIX = 'atrman:metrics:job_total:';

    /**
     * 面板全量数据
     * @return array{queue:array, error_report:array}
     */
    public static function index(): array
    {
        return [
            'queue' => self::queueStatus(),
            'error_report' => ErrorReportService::stats(),
        ];
    }

    /**
     * 队列消费滞后：各队列待消费条数 + 全局延迟/失败集合基数。
     */
    public static function queueStatus(): array
    {
        $empty = [
            'connected' => false,
            'queues' => [],
            'delayed' => 0,
            'failed' => 0,
            'consumer_processes' => (int)config('plugin.webman.redis-queue.process.consumer.count', 0),
        ];
        try {
            $conn = Redis::connection('queue');
            $queues = [];
            foreach (self::QUEUES as $name) {
                $queues[] = [
                    'name' => $name,
                    'waiting' => (int)$conn->lLen(self::WAITING_PREFIX . $name),
                ];
            }
            return [
                'connected' => true,
                'queues' => $queues,
                'delayed' => (int)$conn->zCard(self::DELAYED_KEY),
                'failed' => (int)$conn->zCard(self::FAILED_KEY),
                'consumer_processes' => $empty['consumer_processes'],
            ];
        } catch (\Throwable $e) {
            Log::warning('[可观测性] 队列状态读取失败（面板降级为空值）：' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * 记录一次消费失败回调（Consumer::onConsumeFailure 内调用，走 queue 连接写队列库）。
     *
     * 时序依据 workerman/redis-queue Client::pull()：onConsumeFailure 触发于 attempts
     * 自增之前，故 attempts >= max_attempts 即本次回调后消息将进入失败队列（DLQ），
     * 否则进入重试。返回结果分类供调用方决定是否触发 DLQ 告警。
     *
     * @param string $queue 队列名（Consumer 的 $queue 属性）
     * @param array $package redis-queue 消息包（含 attempts/max_attempts）
     * @return string fail=重试耗尽进 DLQ；retry=还会再投
     */
    public static function recordJobFailure(string $queue, array $package): string
    {
        $result = ((int)($package['attempts'] ?? 0) >= (int)($package['max_attempts'] ?? 0))
            ? 'fail'
            : 'retry';
        try {
            Redis::connection('queue')->incr(self::JOB_COUNTER_PREFIX . $queue . ':' . $result);
        } catch (\Throwable $e) {
            // 计数失败不得影响队列自身的重试/失败判定
            Log::warning('[可观测性] job 失败计数写入失败（不影响消费重试语义）：' . $e->getMessage());
        }
        return $result;
    }
}
