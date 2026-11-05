<?php

namespace app\service;

use app\basic\EventRegistry;
use app\model\notify\NotifyLog;
use app\model\notify\NotifyMessage;
use app\model\notify\NotifyPreference;
use app\model\notify\NotifyTemplate;
use app\model\notify\SmsProvider;
use app\model\system\Config;
use Overtrue\EasySms\EasySms;
use Overtrue\EasySms\Strategies\OrderStrategy;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;
use PHPMailer\PHPMailer\PHPMailer;
use Webman\RedisQueue\Client as RedisQueueClient;

class NotifyService
{
    /**
     * 已注册的自定义通道驱动
     * 格式: ['type' => ['sender' => callable, 'config' => callable|null, 'skip_preference' => bool, 'owner' => string]]
     */
    private static array $drivers = [];

    /**
     * 注册自定义通道驱动（插件通过此方法扩展新通道）
     *
     * 通道的显示名与凭证表单不在此维护：走配置管理体系声明（install.sql 或
     * PluginRegistrar::configSchema），与基座邮件/短信配置同构。
     *
     * @param string $type 通道类型标识（如 'websocket'、'wechat'）
     * @param callable $sender 发送回调：fn(string $to, string $content, array $template, array $config) => array
     * @param callable|null $configResolver 配置解析回调：fn(NotifyTemplate $template) => ?array；通常读 Config::getConfig('{channel}')
     * @param bool $skipPreference 是否跳过用户偏好检查（内部通道设为 true）
     * @param string $owner 归属插件名（插件注册必传，卸载/禁用对称清理用）
     *
     * 示例（插件中注册 WebSocket 通道）：
     *   NotifyService::register('websocket',
     *       fn($to, $content, $template, $config) => WebSocketService::push($to, $content, $template),
     *       fn($template) => Config::getConfig('websocket'),
     *       true,
     *       'am_demo'
     *   );
     */
    public static function register(string $type, callable $sender, ?callable $configResolver = null, bool $skipPreference = false, string $owner = ''): void
    {
        self::$drivers[$type] = [
            'sender' => $sender,
            'config' => $configResolver,
            'skip_preference' => $skipPreference,
            'owner' => $owner,
        ];
    }

    /**
     * 获取所有已注册的通道类型（含内置 + 自定义）
     */
    public static function getRegisteredTypes(): array
    {
        // push 通道由 PushService::registerAsChannel() 在每进程启动时注册（push_enabled 开关生效）
        return array_unique(array_merge(['email', 'sms', 'inbox'], array_keys(self::$drivers)));
    }

    /**
     * 某通道（自定义驱动）是否已注册
     */
    public static function hasRegisteredChannel(string $type): bool
    {
        return isset(self::$drivers[$type]);
    }

    /**
     * 按 owner 清理某插件注册的全部通道（禁用/卸载时由 PluginManager 调用）
     */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        self::$drivers = array_filter(self::$drivers, fn($d) => ($d['owner'] ?? '') !== $owner);
    }

    /**
     * 单通道发送通知
     *
     * @param string $templateCode 模板编码
     * @param string $type 通道类型：email|sms|inbox 或已注册的自定义类型
     * @param string|int $receiver 接收方（邮箱/手机号/用户ID）
     * @param array $variables 模板变量
     * @param array $extra 附加数据（透传给站内信等）
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public static function send(string $templateCode, string $type, string|int $receiver, array $variables = [], array $extra = []): array
    {
        $results = self::sendMulti($templateCode, [$type => $receiver], $variables, $extra);
        return $results[$type] ?? ['success' => false, 'message' => '发送失败', 'data' => []];
    }

    /**
     * 异步投递通知到队列（不阻塞当前请求，由 SendNotifyJob 消费执行）
     *
     * 与同步 send/sendMulti 并存：需要即时反馈的场景（如后台“测试发送”）仍用同步；
     * 事件驱动、无需即时结果的场景（如注册/下单通知）用本方法。
     *
     * @param string $templateCode 模板编码
     * @param array $receiverMap 接收方映射：['email' => 'xx@xx.com', 'sms' => '138xxx', 'inbox' => 用户ID]
     * @param array $variables 模板变量
     * @param array $extra 附加数据
     * @param int $delay 延迟执行秒数，0 为立即
     * @param string $dedupKey 可选幂等去重键（同一键在有效期内只发送一次）
     * @return void
     */
    public static function dispatch(string $templateCode, array $receiverMap, array $variables = [], array $extra = [], int $delay = 0, string $dedupKey = ''): void
    {
        RedisQueueClient::send('send_notify', [
            'template_code' => $templateCode,
            'receiver_map'  => $receiverMap,
            'variables'     => $variables,
            'extra'         => $extra,
            'dedup_key'     => $dedupKey,
        ], $delay);
    }

    /**
     * 异步投递单通道通知到队列（dispatch 的单通道便捷封装）
     *
     * @param string $templateCode 模板编码
     * @param string $type 通道类型：email|sms|inbox 或已注册的自定义类型
     * @param string|int $receiver 接收方
     * @param array $variables 模板变量
     * @param array $extra 附加数据
     * @param int $delay 延迟执行秒数
     * @param string $dedupKey 可选幂等去重键
     * @return void
     */
    public static function dispatchOne(string $templateCode, string $type, string|int $receiver, array $variables = [], array $extra = [], int $delay = 0, string $dedupKey = ''): void
    {
        self::dispatch($templateCode, [$type => $receiver], $variables, $extra, $delay, $dedupKey);
    }

    /**
     * 多通道发送通知（一条日志记录所有通道的发送结果）
     *
     * @param string $templateCode 模板编码
     * @param array $receiverMap 接收方映射：['email' => 'xx@xx.com', 'sms' => '138xxx', 'inbox' => 用户ID]
     * @param array $variables 模板变量
     * @param array $extra 附加数据
     * @return array 各通道结果：['email' => [...], 'sms' => [...], ...]
     */
    public static function sendMulti(string $templateCode, array $receiverMap, array $variables = [], array $extra = []): array
    {
        $template = NotifyTemplate::getByCode($templateCode);
        if (!$template) {
            return ['_error' => ['success' => false, 'message' => "模板不存在：{$templateCode}", 'data' => []]];
        }

        // 过滤有效通道（有接收方、有配置、用户未拒收）
        $channels = [];
        foreach ($receiverMap as $type => $receiver) {
            if (empty($receiver)) {
                continue;
            }

            // 站内信必须有有效用户ID
            if ($type === 'inbox' && (!(int) $receiver || (int) $receiver <= 0)) {
                continue;
            }

            // 短信内容校验
            if ($type === 'sms' && empty($template->sms_template_id) && empty($template->sms_content)) {
                continue;
            }

            // 获取通道配置
            $config = self::getChannelConfig($type, $template);
            if ($config === null) {
                continue;
            }

            // 用户偏好检查（站内信及 skip_preference=true 的自定义通道跳过）
            if (!self::shouldSkipPreference($type) && is_numeric($receiver)) {
                if (!NotifyPreference::isAccepted((int) $receiver, $type)) {
                    $channels[$type] = [
                        'receiver' => (string) $receiver,
                        'config' => $config,
                        'skipped' => true,
                        'skip_reason' => '用户已拒收该类型通知',
                    ];
                    continue;
                }
            }

            $channels[$type] = [
                'receiver' => (string) $receiver,
                'config' => $config,
                'skipped' => false,
            ];
        }

        if (empty($channels)) {
            return ['_error' => ['success' => false, 'message' => '无可用发送通道', 'data' => []]];
        }

        // 渲染模板内容（按通道分别渲染，短信可能用不同内容）
        $systemVars = TemplateEngine::getSystemVariables();
        $rendered = [];
        foreach ($channels as $type => $ch) {
            $rawContent = ($type === 'sms') ? ($template->sms_content ?? '') : ($template->content ?? '');
            $rendered[$type] = [
                'title' => TemplateEngine::render($template->title ?? '', $variables, $systemVars),
                'content' => TemplateEngine::render($rawContent, $variables, $systemVars),
            ];
        }

        // 创建一条发送记录（覆盖所有通道）
        $log = NotifyLog::create([
            'template_id' => $template->id,
            'template_code' => $template->code,
            'title' => $rendered[array_key_first($rendered)]['title'] ?? '',
            'receiver' => implode(', ', array_column($channels, 'receiver')),
            'channels' => array_keys($channels),
            'status' => 0,
        ]);

        // 逐通道发送
        $results = [];
        $channelResults = [];
        $anySuccess = false;

        foreach ($channels as $type => $ch) {
            // 跳过的通道（如用户拒收）
            if ($ch['skipped']) {
                $channelResults[$type] = [
                    'status' => 3,
                    'message' => $ch['skip_reason'] ?? '已跳过',
                    'cost_time' => 0,
                ];
                $results[$type] = ['success' => false, 'message' => $ch['skip_reason'] ?? '已跳过', 'data' => []];
                continue;
            }

            $templateData = $template->toArray();
            $templateData['title'] = $rendered[$type]['title'];
            $templateData['rendered_variables'] = $variables;
            $templateData['log_id'] = $log->id;
            $templateData['extra'] = $extra;

            $startTime = microtime(true);
            try {
                $result = self::dispatchSend($type, $ch['receiver'], $rendered[$type]['content'], $templateData, $ch['config']);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => "{$type}通道发送异常：" . $e->getMessage(), 'data' => []];
            }
            $costTime = (int) ((microtime(true) - $startTime) * 1000);

            $channelResults[$type] = [
                'status' => $result['success'] ? 1 : 2,
                'message' => $result['message'] ?? '',
                'cost_time' => $costTime,
            ];
            $results[$type] = $result;
            if ($result['success']) {
                $anySuccess = true;
            }
        }

        // 更新发送记录（使用查询构造器，避免模型 autoWriteTimestamp 类型检测问题）
        NotifyLog::where('id', $log->id)->update([
            'status' => $anySuccess ? 1 : 2,
            'channels' => json_encode($channelResults, JSON_UNESCAPED_UNICODE),
        ]);

        EventRegistry::emit('notify.sent', [
            'log_id' => (int)$log->id,
            'template_code' => $templateCode,
            'any_success' => $anySuccess,
            'results' => $results,
        ]);

        return $results;
    }

    // ==================== 通道分发 ====================

    /**
     * 分发到对应通道发送（优先查自定义注册，再匹配内置通道）
     */
    private static function dispatchSend(string $type, string $to, string $content, array $template, array $config): array
    {
        // 自定义注册通道
        if (isset(self::$drivers[$type])) {
            return (self::$drivers[$type]['sender'])($to, $content, $template, $config);
        }

        // 内置通道
        return match ($type) {
            'email' => self::sendEmail($to, $content, $template, $config),
            'sms' => self::sendSms($to, $content, $template, $config),
            'inbox' => self::sendInbox($to, $content, $template, $config),
            default => ['success' => false, 'message' => "不支持的通知类型：{$type}", 'data' => []],
        };
    }

    // ==================== 内置通道发送 ====================

    /**
     * 邮件发送（通过 PHPMailer SMTP）
     */
    private static function sendEmail(string $to, string $content, array $template, array $config): array
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['host'] ?? '';
            $mail->Port = (int) ($config['port'] ?? 465);
            $mail->SMTPAuth = true;
            $mail->Username = $config['user'] ?? '';
            $mail->Password = $config['pass'] ?? '';
            $mail->Timeout = 10;
            $security = $config['security'] ?? false;

            $secure = $security==1 ? 'ssl' : 'tls';
            if ($secure) {
                $mail->SMTPSecure = $secure;
            }

            $mail->setFrom($config['user'] ?: '', $config['sign'] ?: ($config['user'] ?: ''));
            $mail->addAddress($to);
            $mail->CharSet = $config['charset'] ?? 'UTF-8';
            $mail->Subject = $template['title'] ?? '系统通知';

            if (str_contains($content, '<')) {
                $mail->isHTML(true);
                $mail->Body = $content;
                $mail->AltBody = strip_tags($content);
            } else {
                $mail->Body = $content;
            }

            $mail->send();
            return ['success' => true, 'message' => '邮件发送成功', 'data' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '邮件发送失败：' . $e->getMessage(), 'data' => []];
        }
    }

    /**
     * 短信发送（通过 EasySms，支持模板短信和自由内容）
     */
    private static function sendSms(string $to, string $content, array $template, array $config): array
    {
        try {
            $gatewayCode = $config['code'] ?? '';
            $gatewayConfig = self::normalizeConfig((array) ($config['config'] ?? []));

            $easySms = new EasySms([
                'timeout' => 5.0,
                'default' => [
                    'strategy' => OrderStrategy::class,
                    'gateways' => [$gatewayCode],
                ],
                'gateways' => [$gatewayCode => $gatewayConfig],
            ]);

            $params = !empty($template['sms_template_id'])
                ? ['template' => $template['sms_template_id'], 'data' => $template['rendered_variables'] ?? []]
                : ['content' => $content];

            $result = $easySms->send($to, $params);
            return ['success' => true, 'message' => '短信发送成功', 'data' => $result];
        } catch (NoGatewayAvailableException $e) {
            $details = array_map(fn($ex) => $ex->getMessage(), $e->getExceptions());
            return ['success' => false, 'message' => '短信发送失败：' . json_encode($details, JSON_UNESCAPED_UNICODE), 'data' => []];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '短信发送失败：' . $e->getMessage(), 'data' => []];
        }
    }

    /**
     * 站内信发送（写入 notify_message 表）
     */
    private static function sendInbox(string $to, string $content, array $template, array $config): array
    {
        try {
            $userId = (int) $to;
            if ($userId <= 0) {
                return ['success' => false, 'message' => '无效的用户ID', 'data' => []];
            }

            $message = NotifyMessage::create([
                'log_id' => $template['log_id'] ?? null,
                'user_id' => $userId,
                'title' => $template['title'] ?? '系统通知',
                'content' => $content,
                'type' => $template['notify_type'] ?? 'system',
                'extra' => $template['extra'] ?? null,
            ]);

            return ['success' => true, 'message' => '站内信发送成功', 'data' => ['message_id' => $message->id]];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '站内信发送失败：' . $e->getMessage(), 'data' => []];
        }
    }

    // ==================== 配置获取 ====================

    /**
     * 获取通道配置（优先查自定义注册，再匹配内置通道）
     */
    private static function getChannelConfig(string $type, NotifyTemplate $template): ?array
    {
        // 自定义注册通道
        if (isset(self::$drivers[$type])) {
            return self::$drivers[$type]['config']
                ? (self::$drivers[$type]['config'])($template)
                : [];
        }

        // 内置通道
        return match ($type) {
            'sms' => self::getSmsProviderConfig($template),
            'email' => self::getEmailConfig(),
            'inbox' => [],
            default => null,
        };
    }

    /**
     * 判断通道是否跳过用户偏好检查
     */
    private static function shouldSkipPreference(string $type): bool
    {
        if ($type === 'inbox') {
            return true;
        }
        return self::$drivers[$type]['skip_preference'] ?? false;
    }

    /**
     * 获取短信供应商配置（优先级：模板指定 > 默认供应商 > 系统配置）
     */
    private static function getSmsProviderConfig(NotifyTemplate $template): ?array
    {
        // 1. 模板指定了供应商
        $provider = null;
        if ($template->sms_provider_id) {
            $provider = SmsProvider::find($template->sms_provider_id);
        }
        // 2. 使用默认供应商
        if (!$provider) {
            $provider = SmsProvider::getDefaultProvider();
        }
        // 3. 从系统配置获取
        if (!$provider) {
            $smsConfig = Config::getConfig('sms');
            $defaultCode = $smsConfig['default_provider'] ?? '';
            if ($defaultCode) {
                $provider = SmsProvider::getByCode($defaultCode);
            }
        }
        if (!$provider) {
            return null;
        }

        return ['code' => $provider->code, 'config' => $provider->config];
    }

    /**
     * 获取邮件 SMTP 配置
     */
    private static function getEmailConfig(): ?array
    {
        $config = Config::getConfig('email');
        if (empty($config) || empty($config['host'])) {
            return null;
        }
        return $config;
    }

    // ==================== 工具方法 ====================

    /**
     * 递归将 stdClass 转为关联数组
     * ThinkORM JSON 字段解码后，嵌套对象为 stdClass，EasySms 等第三方库需要纯数组
     */
    private static function normalizeConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            $config[$key] = is_object($value)
                ? self::normalizeConfig((array) $value)
                : (is_array($value) ? self::normalizeConfig($value) : $value);
        }
        return $config;
    }
}
