<?php

namespace app\service\system;

use support\Log;
use support\Redis;

/**
 * 通用维护标记（与 CoreUpgradeService 的升级专用标记并存）。
 *
 * 系统恢复等队列任务执行期间置标记，SystemMaintenance 中间件据此对访客展示维护页、
 * 拦截业务写请求。与升级标记刻意分键：两者生命周期、TTL 与残留自愈口径不同，
 * 混在一个键里会让恢复任务误触升级的 hold/锁联动逻辑。
 *
 * 口径：
 * - enter 失败（Redis 不可用）属 fail-closed：调用方应中止任务，不能在没有维护栏的
 *   情况下做覆盖目录/导库这类半恢复操作；
 * - isActive/info 读取失败 fail-open 返回非维护态，避免缓存故障把全站锁死（标记本身带 TTL 兜底）。
 */
class MaintenanceService
{
    /** 维护标记键（独立于 atrman:core:upgrade:maintenance） */
    public const FLAG_KEY = 'atrman:maintenance:flag';

    /** 标记兜底 TTL：任务正常路径会主动 leave，此处只防进程被强杀后标记永驻 */
    private const FLAG_TTL = 3600;

    /**
     * 进入维护模式。
     *
     * @param string $scene 场景标识（如 system_restore），写入标记供排查
     * @param string $label 面向用户的动作名（如「系统恢复」），用于维护页文案
     * @throws \RuntimeException Redis 写入失败时抛出，调用方必须中止任务
     */
    public static function enter(string $scene, string $label): void
    {
        try {
            Redis::setex(self::FLAG_KEY, self::FLAG_TTL, json_encode([
                'scene' => $scene,
                'label' => $label,
                'since' => time(),
                'since_text' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('[维护模式] 标记写入失败，任务必须中止：' . $e->getMessage());
            throw new \RuntimeException('维护模式开启失败（Redis 不可用），为避免半恢复状态已中止任务', 0, $e);
        }
    }

    /** 解除维护标记（失败只记日志，TTL 会兜底过期）。 */
    public static function leave(): void
    {
        try {
            Redis::del(self::FLAG_KEY);
        } catch (\Throwable $e) {
            Log::warning('[维护模式] 标记解除失败，等待 TTL 自动过期：' . $e->getMessage());
        }
    }

    /** 是否处于通用维护态（读取异常 fail-open）。 */
    public static function isActive(): bool
    {
        try {
            return (string)Redis::get(self::FLAG_KEY) !== '';
        } catch (\Throwable $e) {
            Log::warning('[维护模式] 标记读取失败，按非维护态放行：' . $e->getMessage());
            return false;
        }
    }

    /** 维护标记详情（无标记或读取失败返回空数组）。 */
    public static function info(): array
    {
        try {
            $raw = (string)Redis::get(self::FLAG_KEY);
            if ($raw === '') {
                return [];
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
