<?php

namespace app\service\system;

use app\model\notify\NotifyMessage;
use app\model\system\Backup;
use support\Log;
use support\Redis;

/**
 * 备份/恢复后台队列任务（信封队列 handler：system_backup_run / system_restore_run）。
 *
 * 设计口径：
 * - 两类任务共用一把串行锁：恢复内部会再做一次安全备份，与并发备份/恢复同时跑会互相
 *   覆盖工作目录与插件目录，抢不到锁直接判失败，不排队等待（由用户稍后重试）；
 * - handler 全程吞异常：覆盖目录/导库/mysqldump 都不具备盲目重试安全性，不能把异常抛给
 *   队列触发 max_attempts 次重投，失败终态落库/落快照，由用户人工决定是否再发起；
 * - 恢复期间置 MaintenanceService 维护标记，中间件拦截访客写请求；enter 失败 fail-closed；
 * - 终态给操作者发站内信；CLI 触发（operator=0）无收件人，只记日志。
 */
class BackupJobService
{
    public const HANDLER_BACKUP = 'system_backup_run';

    public const HANDLER_RESTORE = 'system_restore_run';

    /** 备份/恢复串行锁 */
    private const LOCK_KEY = 'atrman:task:backup:lock';

    /** 锁兜底 TTL：正常路径 finally 主动释放，此处只防进程被强杀 */
    private const LOCK_TTL = 3600;

    /** 恢复任务快照键前缀（前端轮询 GET /sys/backup/task） */
    private const TASK_KEY_PREFIX = 'atrman:bg-task:restore:';

    /** 快照保留时长：终态后仍留一天供轮询晚到的页面取结果 */
    private const TASK_TTL = 86400;

    /**
     * 系统备份任务体。payload：backup_id（占位记录）、operator_uid。
     */
    public static function runBackupJob(array $payload): void
    {
        $backupId = (int)($payload['backup_id'] ?? 0);
        $operatorUid = (int)($payload['operator_uid'] ?? 0);
        if ($backupId <= 0) {
            Log::error('[后台任务] 系统备份任务参数非法：' . json_encode($payload, JSON_UNESCAPED_UNICODE));
            return;
        }

        $token = self::acquireLock();
        if ($token === '') {
            $record = Backup::find($backupId);
            if ($record) {
                BackupService::markBackupFailed($record, '已有备份或恢复任务在执行，请稍后重试');
            }
            self::notify($operatorUid, '系统备份未能开始', '已有备份或恢复任务在执行，本次备份未运行，请稍后重新发起。', $backupId);
            return;
        }

        try {
            $record = BackupService::backupSystem($backupId);
            self::notify(
                $operatorUid,
                '系统备份完成',
                '系统备份「' . $record->title . '」已生成，可在备份列表下载或恢复。',
                $backupId
            );
        } catch (\Throwable $e) {
            // 失败态已由 backupSystem 回写占位行，这里只补通知与日志
            Log::error('[后台任务] 系统备份失败 backup_id=' . $backupId . '：' . $e->getMessage());
            self::notify($operatorUid, '系统备份失败', '系统备份执行失败：' . mb_substr($e->getMessage(), 0, 200), $backupId);
        } finally {
            self::releaseLock($token);
        }
    }

    /**
     * 系统恢复任务体。payload：job_id、backup_id、operator_uid。
     * 全程不向外抛异常（见类头口径）。
     */
    public static function runRestoreJob(array $payload): void
    {
        $jobId = (string)($payload['job_id'] ?? '');
        $backupId = (int)($payload['backup_id'] ?? 0);
        $operatorUid = (int)($payload['operator_uid'] ?? 0);
        if ($jobId === '' || $backupId <= 0) {
            Log::error('[后台任务] 系统恢复任务参数非法：' . json_encode($payload, JSON_UNESCAPED_UNICODE));
            return;
        }

        $token = self::acquireLock();
        if ($token === '') {
            self::writeTaskSnapshot($jobId, [
                'status' => 'failed',
                'message' => '已有备份或恢复任务在执行，请稍后重试',
            ]);
            self::notify($operatorUid, '系统恢复未能开始', '已有备份或恢复任务在执行，本次恢复未运行，请稍后重新发起。', $backupId);
            return;
        }

        $maintenanceOn = false;
        try {
            self::writeTaskSnapshot($jobId, [
                'status' => 'running',
                'message' => '恢复任务执行中，站点已进入维护模式',
                'backup_id' => $backupId,
            ]);

            // 维护标记立不住就不许开工：没有维护栏的覆盖/导库会让访客打到半恢复系统
            MaintenanceService::enter('system_restore', '系统恢复');
            $maintenanceOn = true;

            $result = BackupService::restoreSystem($backupId);
            self::writeTaskSnapshot($jobId, [
                'status' => 'success',
                'message' => (string)($result['message'] ?? '系统恢复成功，请重启服务使新配置生效'),
                'result' => $result,
                'backup_id' => $backupId,
            ]);
            self::notify($operatorUid, '系统恢复完成', (string)($result['message'] ?? '系统恢复成功，请重启服务使新配置生效。'), $backupId);
        } catch (\Throwable $e) {
            Log::error('[后台任务] 系统恢复失败 job=' . $jobId . ' backup_id=' . $backupId . '：' . $e->getMessage());
            self::writeTaskSnapshot($jobId, [
                'status' => 'failed',
                'message' => mb_substr($e->getMessage(), 0, 500),
                'backup_id' => $backupId,
            ]);
            self::notify($operatorUid, '系统恢复失败', '系统恢复执行失败：' . mb_substr($e->getMessage(), 0, 200), $backupId);
        } finally {
            if ($maintenanceOn) {
                MaintenanceService::leave();
            }
            self::releaseLock($token);
        }
    }

    /** 读恢复任务快照（供控制器轮询端点）。 */
    public static function taskSnapshot(string $jobId): array
    {
        if ($jobId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $jobId)) {
            return [];
        }
        try {
            $raw = (string)Redis::get(self::TASK_KEY_PREFIX . $jobId);
            if ($raw === '') {
                return [];
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** 抢串行锁，成功返回随机令牌，失败返回空串。 */
    private static function acquireLock(): string
    {
        try {
            $token = bin2hex(random_bytes(16));
            $ok = Redis::set(self::LOCK_KEY, $token, 'EX', self::LOCK_TTL, 'NX');
            return $ok ? $token : '';
        } catch (\Throwable $e) {
            Log::error('[后台任务] 串行锁获取失败，按拒绝执行处理：' . $e->getMessage());
            return '';
        }
    }

    /** Lua compare-and-delete：仅持有者可释放，避免误删别的任务的锁。 */
    private static function releaseLock(string $token): void
    {
        try {
            Redis::eval(
                'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
                1,
                self::LOCK_KEY,
                $token
            );
        } catch (\Throwable $e) {
            Log::warning('[后台任务] 锁主动释放失败，等待 TTL 到期：' . $e->getMessage());
        }
    }

    /** 写恢复任务快照（合并 started_at 等既有字段）。 */
    private static function writeTaskSnapshot(string $jobId, array $patch): void
    {
        try {
            $key = self::TASK_KEY_PREFIX . $jobId;
            $data = array_merge([
                'job_id' => $jobId,
                'started_at' => date('Y-m-d H:i:s'),
            ], self::taskSnapshot($jobId), $patch, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Redis::setex($key, self::TASK_TTL, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::error('[后台任务] 任务快照写入失败 job=' . $jobId . '：' . $e->getMessage());
        }
    }

    /** 终态站内信；operator 为 0（CLI/无请求上下文）时只记日志。 */
    private static function notify(int $operatorUid, string $title, string $content, int $backupId): void
    {
        if ($operatorUid <= 0) {
            Log::info('[后台任务] 无收件人（operator=0），跳过站内信：' . $title);
            return;
        }
        try {
            NotifyMessage::create([
                'user_id' => $operatorUid,
                'title' => $title,
                'content' => $content,
                'type' => 'system',
                'extra' => ['backup_id' => $backupId],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[后台任务] 站内信写入失败：' . $e->getMessage());
        }
    }
}
