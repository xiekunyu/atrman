<?php

namespace app\queue\redis;

use app\model\plugin\App as Plugin;
use app\service\StoreService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * License 心跳队列消费者
 *
 * 消费由 PluginStatusCheck 中间件投递的心跳任务，异步执行付费应用的
 * 在线授权校验，避免在 HTTP worker 内同步阻塞（详见开发规范 2.8 队列规范）。
 */
class LicenseHeartbeatJob implements Consumer
{
    /**
     * 消费的队列名，需与 StoreService::dispatchHeartbeat() 中一致
     * @var string
     */
    public $queue = 'license_heartbeat';

    /**
     * Redis 连接名
     * @var string
     */
    public $connection = 'default';

    /**
     * 消费心跳任务
     * @param array $data {plugin_id: int}
     */
    public function consume($data): void
    {
        $pluginId = (int)($data['plugin_id'] ?? 0);
        if ($pluginId <= 0) {
            return;
        }

        $plugin = Plugin::findOrEmpty($pluginId);
        if ($plugin->isEmpty() || (int)$plugin->status !== 1) {
            return;
        }
        if (!StoreService::isLicenseRequired($plugin)) {
            return;
        }

        $result = StoreService::heartbeat($plugin);
        if (!$result['valid']) {
            Log::warning(sprintf(
                'License 心跳未通过 [%s]：%s',
                $plugin->name,
                $result['message'] ?? ''
            ));
        }
    }

    /**
     * 消费失败回调
     */
    public function onConsumeFailure(Throwable $exception, $package): void
    {
        Log::error('License 心跳执行失败：' . $exception->getMessage());
    }
}