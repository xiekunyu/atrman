<?php

namespace app\queue\redis;

use app\service\NotifyService;
use support\Log;
use support\Redis;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 通知发送队列消费者
 *
 * 消费由 NotifyService::dispatch() 投递的任务，异步执行邮件/短信等外部 IO，
 * 避免在 HTTP worker 内同步阻塞（详见开发规范 2.8 队列规范）。
 */
class SendNotifyJob implements Consumer
{
    /**
     * 消费的队列名，需与 NotifyService::dispatch() 中一致
     * @var string
     */
    public $queue = 'send_notify';

    /**
     * Redis 连接名，默认使用插件配置的 default 连接
     * @var string
     */
    public $connection = 'default';

    /**
     * 幂等去重键的存活时间（秒）
     * 队列任务可能被重试，同一 dedup_key 在有效期内只执行一次
     */
    private const DEDUP_TTL = 86400;

    /**
     * 消费处理
     *
     * @param array $data 任务数据：
     *        - template_code string 模板编码（必填）
     *        - receiver_map  array  接收方映射，如 ['email'=>'a@b.com','sms'=>'138...','inbox'=>uid]（必填）
     *        - variables    array  模板变量
     *        - extra        array  附加数据
     *        - dedup_key    string  可选，业务唯一键，用于幂等去重
     * @return void
     * @throws Throwable 基础设施异常时抛出以触发队列重试
     */
    public function consume($data): void
    {
        $templateCode = $data['template_code'] ?? '';
        $receiverMap  = $data['receiver_map'] ?? [];

        // 参数非法属于永久性错误，记录后丢弃，不触发重试
        if (empty($templateCode) || empty($receiverMap) || !is_array($receiverMap)) {
            Log::warning('[SendNotifyJob] 任务参数非法，已丢弃', ['data' => $data]);
            return;
        }

        // 幂等：同一 dedup_key 在有效期内只处理一次（重试/重复投递场景）
        $dedupKey = $data['dedup_key'] ?? '';
        if ($dedupKey !== '' && !self::acquireDedup($dedupKey)) {
            Log::info('[SendNotifyJob] 命中去重键，跳过重复执行', ['dedup_key' => $dedupKey]);
            return;
        }

        $variables = $data['variables'] ?? [];
        $extra     = $data['extra'] ?? [];

        try {
            $results = NotifyService::sendMulti($templateCode, $receiverMap, $variables, $extra);

            // 发送级失败（模板不存在、无可用通道、通道发送失败等）已由 sendMulti 内部
            // 捕获并写入 NotifyLog，属于业务结果而非基础设施故障，不在此重试；
            // 需要人工重试时使用 NotifyLogController::retry。
            Log::info('[SendNotifyJob] 通知异步发送完成', [
                'template_code' => $templateCode,
                'results'       => $results,
            ]);
        } catch (Throwable $e) {
            // DB 连接中断等基础设施异常：清理去重键并抛出，交给队列重试机制
            if ($dedupKey !== '') {
                Redis::del(self::dedupKeyName($dedupKey));
            }
            Log::error('[SendNotifyJob] 通知发送异常，将重试', [
                'template_code' => $templateCode,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 重试次数耗尽后的最终失败处理
     *
     * @param Throwable $e
     * @param array $package 原始消息包
     * @return void
     */
    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('[SendNotifyJob] 通知发送重试耗尽，进入失败队列', [
            'package' => $package,
            'error'   => $e->getMessage(),
        ]);
    }

    /**
     * 抢占去重键（SETNX + TTL），成功返回 true，已存在返回 false
     */
    private static function acquireDedup(string $dedupKey): bool
    {
        $name = self::dedupKeyName($dedupKey);
        // 位置参数 'EX', ttl, 'NX'：成功返回 OK，键已存在返回 false/null；
        // 写成 ['NX', 'EX' => ttl] 会在 PhpRedisConnection::set 里拿数组当键，PHP 8.x 抛 TypeError
        $ok = Redis::set($name, 1, 'EX', self::DEDUP_TTL, 'NX');
        return (bool) $ok;
    }

    /**
     * 去重键完整名称
     */
    private static function dedupKeyName(string $dedupKey): string
    {
        return 'atrman:notify:dedup:' . $dedupKey;
    }
}
