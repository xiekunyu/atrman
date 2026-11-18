<?php
// app/bootstrap/PluginBootstrapper.php

namespace app\bootstrap;

use app\service\system\AuditService;
use app\service\plugin\PluginManager;
use app\service\plugin\ReleaseManifestService;
use support\Log;
use support\Redis;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 插件运行时装配引导。
 *
 * Webman 会在每个 worker 进程 onWorkerStart 时执行本类的 start()，
 * 借此对"已启用"插件逐个调用 boot()，重建进程级静态注册（如 IdentityService
 * 的 provider/extender）。需排在 ThinkOrm 之后，确保数据库连接已初始化。
 */
class PluginBootstrapper implements Bootstrap
{
    /** 进程内 memo：start() 每 worker 执行一次，防同进程重复审计 */
    private static bool $modeAudited = false;

    public static function start(?Worker $worker)
    {
        self::auditManifestModeIfDowngraded();

        // 先预热基座接管点默认实现：把 config/plugin_extend.php 的 provide() 登记留在启动阶段，
        // 否则首次请求 make() 时的懒加载会撞 RegistryGuard 的请求期只读断言
        \app\basic\ServiceRegistry::warmProviders();

        // 基座内置扩展通道先于插件 boot 注册（每进程一次，内部幂等；受 push_enabled 开关控制）
        \app\service\support\PushService::registerAsChannel();
        PluginManager::bootPlugins();

        // 基座自身队列 handler：插件 Composer 依赖安装（在消费进程内跑 composer，逐行推流）。
        // 每进程注册幂等覆盖，确保 redis-queue 消费进程能按名解析到。
        \app\service\support\QueueService::consume(
            \app\service\plugin\PluginComposerService::HANDLER,
            fn(array $p) => \app\service\plugin\PluginComposerService::runInstall($p),
            'base'
        );

        // 基座自身队列 handler：系统备份/恢复后台任务。
        // 任务体内部已全量吞异常并把终态落库/落快照：覆盖与导库不具备盲目重试安全性，
        // 绝不能让异常外逸触发队列重投。
        \app\service\support\QueueService::consume(
            \app\service\system\BackupJobService::HANDLER_BACKUP,
            fn(array $p) => \app\service\system\BackupJobService::runBackupJob($p),
            'base'
        );
        \app\service\support\QueueService::consume(
            \app\service\system\BackupJobService::HANDLER_RESTORE,
            fn(array $p) => \app\service\system\BackupJobService::runRestoreJob($p),
            'base'
        );

        // 基座自身队列 handler：主程序（基座）升级执行。
        // 备份 → 逐文件建快照后覆盖差异文件 → 迁移 → composer → 版本落库 → 收尾 → 重载，
        // 失败按快照自动回滚，全程置维护标记（SystemMaintenance 中间件拦截访客与业务写请求）。
        // 此处必须吞异常：升级任务非幂等，CoreUpgradeService::run 一旦向外抛异常，
        // PluginJobConsumer 会交给队列重试，导致已覆盖过的包被重复执行。
        \app\service\support\QueueService::consume(
            \app\service\plugin\CoreUpgradeService::HANDLER,
            function (array $p): void {
                try {
                    \app\service\plugin\CoreUpgradeService::run($p);
                } catch (\Throwable $e) {
                    \support\Log::error('[CoreUpgrade] 升级任务收尾异常（不交队列重试，请人工核对站点状态）：' . $e->getMessage());
                }
            },
            'base'
        );

        // 启动期注册结束；此后 Registry 进入只读阶段（debug 模式违规变异抛 LogicException）
        \app\basic\RegistryGuard::enterRequestPhase();

        // 队列 Redis 启动探活：连接/鉴权/队列库任一不通，消息投递与消费都会静默失效，
        // 必须在启动日志里留下显著 error，而不是等首条消息报错才发现
        self::probeQueueRedis();
    }

    /**
     * 队列 Redis 探活：按 redis-queue 同一份 env 配置直连（独立队列库），
     * 不走连接池（onWorkerStart 无请求上下文，借出的连接无法归还）。
     * 失败只记显著 error，不阻断进程启动——Redis 恢复后连接会按需重建。
     */
    private static function probeQueueRedis(): void
    {
        try {
            $host = (string)env('REDIS_HOST', '127.0.0.1');
            $port = (int)env('REDIS_PORT', 6379) ?: 6379;
            $password = trim((string)env('REDIS_PASSWORD', ''));
            $db = (int)env('REDIS_QUEUE_DB', 1);

            $client = new \Redis();
            $client->connect($host, $port, 3);
            if ($password !== '') {
                $client->auth($password);
            }
            $client->select($db);
            $pong = $client->ping();
            $client->close();
            Log::info('[redis-queue] 队列 Redis 启动探活成功（db=' . $db . '，响应 ' . (is_string($pong) ? $pong : '+PONG') . '）');
        } catch (\Throwable $e) {
            Log::error('[redis-queue] 队列 Redis 启动探活失败，消息投递与消费将不可用：' . $e->getMessage());
        }
    }

    /**
     * 发布清单验签模式降级审计：strict 之外（shadow/off）都意味着安装包完整性
     * 强校验被人为放松，启动即落安全审计并供后台常驻告警条展示。
     *
     * 多 worker + 频繁重启会把 start() 放大成每进程一次，故用 Redis NX 按模式去重（24h 一条）；
     * Redis 故障时退化为每进程一条，不能因去重组件故障而漏掉降级留痕。
     */
    private static function auditManifestModeIfDowngraded(): void
    {
        if (self::$modeAudited) {
            return;
        }
        self::$modeAudited = true;

        try {
            $mode = ReleaseManifestService::mode();
        } catch (\Throwable $e) {
            Log::error('[发布清单] 启动期模式读取失败：' . $e->getMessage());
            return;
        }
        if ($mode === ReleaseManifestService::MODE_STRICT) {
            return;
        }

        try {
            $dedupKey = 'atrman:audit:manifest-downgrade:' . $mode;
            $acquired = Redis::set($dedupKey, date('c'), 'EX', 86400, 'NX');
            if (!$acquired) {
                return; // 24h 内已有进程记过
            }
        } catch (\Throwable $e) {
            Log::warning('[安全审计] 降级事件去重键写入失败，退化为每进程记录：' . $e->getMessage());
        }

        AuditService::record(AuditService::EVENT_MANIFEST_DOWNGRADE, [
            'mode' => $mode,
            'hint' => '发布清单验签处于非 strict 模式，安装包完整性强校验已降级，请尽快恢复 strict',
        ]);
        Log::error('[安全审计] 发布清单验签模式已降级为 ' . $mode . '，安装包完整性保护被削弱');
    }
}
