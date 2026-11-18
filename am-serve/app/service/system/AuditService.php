<?php

namespace app\service\system;

use app\model\system\SystemLog;
use app\utils\ClientInfo;
use support\Log;

/**
 * 安全审计事件（独立于操作日志中间件的主动留痕入口）。
 *
 * 操作日志（OperateLog 中间件 → user.operateLog 事件）只记录「谁调了哪个接口」，
 * 而 P1 安全整改要求对高风险动作记录独立事件类型与结构化明细（包名、sha256、IP、操作者）。
 * 复用 system_log 表：business_name 存事件类型，request_data 存 JSON 明细。
 *
 * 事件类型：
 * - plugin.restore.unsigned   历史未签名备份包显式放行恢复
 * - plugin.install.thirdparty 本地第三方插件包安装（非官方分发名，无发布清单背书）
 * - release_manifest.downgrade 发布清单验签模式从 strict 降级（shadow/off）
 */
class AuditService
{
    public const EVENT_RESTORE_UNSIGNED = 'plugin.restore.unsigned';

    public const EVENT_INSTALL_THIRDPARTY = 'plugin.install.thirdparty';

    public const EVENT_MANIFEST_DOWNGRADE = 'release_manifest.downgrade';

    /**
     * 触发 webhook 告警的安全事件：在 record() 统一收口，三个既有调用点无需各自接告警，
     * 也保证未来新增调用点不会漏告。其余审计事件只落库不告警。
     */
    private const SECURITY_ALERT_EVENTS = [
        self::EVENT_RESTORE_UNSIGNED,
        self::EVENT_INSTALL_THIRDPARTY,
        self::EVENT_MANIFEST_DOWNGRADE,
    ];

    /**
     * 写一条安全审计事件。
     *
     * @param string $event 事件类型常量
     * @param array $detail 结构化明细（包名、sha256、来源等；不要放口令/令牌）
     * @param string|null $ip 显式指定 IP（队列/启动等无请求场景），默认取当前请求
     * @return bool 是否落库成功；未签名恢复等强制留痕场景的调用方应在 false 时中止动作
     */
    public static function record(string $event, array $detail = [], ?string $ip = null): bool
    {
        $stored = false;
        try {
            $request = request();
            $info = $request ? (getCurrentInfo() ?: []) : [];
            if ($ip === null && $request) {
                $ip = ClientInfo::clientIp($request);
            }
            $ip = (string)($ip ?? '');
            $uid = (int)($info['id'] ?? currentUserId());

            SystemLog::create([
                'username' => (string)($info['username'] ?? ''),
                'app' => $request?->plugin ?: '主应用',
                'method' => $request?->method() ?: 'CLI',
                'router' => $request?->uri() ?: '',
                'business_name' => $event,
                'ip' => $ip,
                'ip_location' => $ip !== '' ? ClientInfo::ipLocation($ip) : '未知',
                'request_data' => (string)json_encode(
                    ['event' => $event, 'detail' => $detail],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'remark' => '安全审计',
                'created_by' => $uid > 0 ? $uid : null,
                'updated_by' => $uid > 0 ? $uid : null,
            ]);
            $stored = true;
        } catch (\Throwable $e) {
            Log::error('[安全审计] 事件 ' . $event . ' 写入失败：' . $e->getMessage());
        }

        // 高风险安全事件旁路告警（AlertService 内部全兜底，告警失败不改变审计落库返回值）
        if (in_array($event, self::SECURITY_ALERT_EVENTS, true)) {
            AlertService::send($event, $detail);
        }

        return $stored;
    }
}
