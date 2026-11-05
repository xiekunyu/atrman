<?php

namespace app\service;

use app\basic\ChannelAuthRegistry;
use support\Log;
use Webman\Push\Api;

/**
 * 消息推送统一门面
 *
 * 封装 webman/push（Pusher 协议）的 Api::trigger，一个进程复用同一 Api 实例；
 * 推送失败仅记日志返回 false，不阻断主业务（失败降级）。
 *
 * 私有频道约定：private-sid_{sid}（与登录主体 sid 对齐），
 * 订阅鉴权由 ChannelAuthRegistry 裁决，默认仅 sid 本人可订阅。
 */
class PushService
{
    private static ?Api $api = null;

    /**
     * 向指定频道推送
     *
     * @param string $channel 频道名（公共频道直接命名；私有频道须 private- 前缀）
     * @param string $event 事件名（域.动作，如 order.created）
     * @param array $data 载荷（须可 json_encode）
     * @return bool 是否推送成功（推送服务不可用仅记日志，不抛异常）
     */
    public static function toChannel(string $channel, string $event, array $data): bool
    {
        if (!self::enabled()) {
            return false;
        }
        try {
            self::api()->trigger($channel, $event, $data);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[PushService] 推送失败：' . $e->getMessage(), ['channel' => $channel, 'event' => $event]);
            return false;
        }
    }

    /**
     * 向指定用户（sid）的私有频道推送
     */
    public static function toUser(string $sid, string $event, array $data): bool
    {
        if ($sid === '') {
            return false;
        }
        return self::toChannel(ChannelAuthRegistry::PRIVATE_USER_PREFIX . $sid, $event, $data);
    }

    /**
     * 把 push 注册为 NotifyService 内置通道（由 PluginBootstrapper 在每进程启动时调用一次）
     *
     * 通道接收方 $to 为用户 sid；skip_preference=true（实时消息不参与拒收偏好）。
     */
    public static function registerAsChannel(): void
    {
        if (!self::enabled() || NotifyService::hasRegisteredChannel('push')) {
            return;
        }
        NotifyService::register(
            'push',
            fn(string $to, string $content, array $template, array $config): array => self::toUser(
                $to,
                (string)($template['code'] ?? 'notify'),
                [
                    'title' => $template['title'] ?? '系统通知',
                    'content' => $content,
                    'log_id' => $template['log_id'] ?? null,
                    'extra' => $template['extra'] ?? [],
                ]
            )
                ? ['success' => true, 'message' => '推送成功', 'data' => []]
                : ['success' => false, 'message' => '推送失败', 'data' => []],
            null,
            true
        );
    }

    /** 进程内复用同一 Api 实例 */
    private static function api(): Api
    {
        if (self::$api === null) {
            self::$api = new Api(
                str_replace('0.0.0.0', '127.0.0.1', (string)config('plugin.webman.push.app.api')),
                (string)config('plugin.webman.push.app.app_key'),
                (string)config('plugin.webman.push.app.app_secret')
            );
        }
        return self::$api;
    }

    /** 特性开关（默认开启；未调用推送时无任何流量） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.push_enabled', true) : true;
    }
}
