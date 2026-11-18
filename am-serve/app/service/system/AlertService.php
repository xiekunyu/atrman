<?php

namespace app\service\system;

use support\Log;
use support\Redis;

/**
 * 告警 webhook + Redis 冷却节流。
 *
 * 无新 composer 依赖：HTTP 复用 guzzle，冷却走 SET NX EX。
 * 契约：任何异常都在本类内吞掉并 Log::error，调用方（队列消费、安全审计、
 * 心跳任务）的主流程永远不被告警故障带垮；未配置 ALERT_WEBHOOK_URL 时静默跳过。
 */
class AlertService
{
    /** 同事件冷却秒数：冷却期内重复事件只发一次，防告警风暴 */
    private const COOLDOWN_TTL = 300;

    private const COOLDOWN_PREFIX = 'atrman:alert:cooldown:';

    /**
     * 发送一条告警。
     *
     * @param string $event 事件标识（如 queue.plugin_job.dlq、plugin.install.thirdparty）
     * @param array $detail 结构化明细（不要放口令/令牌等敏感字段）
     */
    public static function send(string $event, array $detail = []): void
    {
        try {
            $url = (string)env('ALERT_WEBHOOK_URL', '');
            // 未配置 webhook：机制静默休眠，不留错误日志
            if ($url === '') {
                return;
            }
            if (!self::acquireCooldown($event)) {
                return;
            }

            $client = new \GuzzleHttp\Client([
                'timeout' => 3,
                'connect_timeout' => 3,
            ]);
            $client->request('POST', $url, [
                'json' => [
                    'event' => $event,
                    'detail' => $detail,
                    'time' => date('c'),
                    'host' => request()?->host() ?: (string)gethostname(),
                ],
                'http_errors' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[告警] webhook 发送失败（event=' . $event . '）：' . $e->getMessage());
        }
    }

    /**
     * 抢占冷却键（SET NX EX），成功才允许发送。
     * Redis 故障时 fail-open 放行——宁重复告警不可漏告。
     * NX/EX 用位置参数（同 SendNotifyJob 约定）：选项数组形式在 PHP 8.x 抛 TypeError。
     */
    private static function acquireCooldown(string $event): bool
    {
        try {
            $ok = Redis::set(self::COOLDOWN_PREFIX . $event, 1, 'EX', self::COOLDOWN_TTL, 'NX');
            return (bool)$ok;
        } catch (\Throwable $e) {
            Log::warning('[告警] 冷却键写入失败（fail-open 照常发送，event=' . $event . '）：' . $e->getMessage());
            return true;
        }
    }
}
