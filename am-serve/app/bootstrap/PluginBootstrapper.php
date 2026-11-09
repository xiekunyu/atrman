<?php
// app/bootstrap/PluginBootstrapper.php

namespace app\bootstrap;

use app\service\PluginManager;
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
    public static function start(?Worker $worker)
    {
        // 先预热基座接管点默认实现：把 config/plugin_extend.php 的 provide() 登记留在启动阶段，
        // 否则首次请求 make() 时的懒加载会撞 RegistryGuard 的请求期只读断言
        \app\basic\ServiceRegistry::warmProviders();

        // 基座内置扩展通道先于插件 boot 注册（每进程一次，内部幂等；受 push_enabled 开关控制）
        \app\service\PushService::registerAsChannel();
        PluginManager::bootPlugins();

        // 基座自身队列 handler：插件 Composer 依赖安装（在消费进程内跑 composer，逐行推流）。
        // 每进程注册幂等覆盖，确保 redis-queue 消费进程能按名解析到。
        \app\service\QueueService::consume(
            \app\service\PluginComposerService::HANDLER,
            fn(array $p) => \app\service\PluginComposerService::runInstall($p),
            'base'
        );

        // 基座自身队列 handler：主程序（基座）升级执行。
        // 备份 → 逐文件建快照后覆盖差异文件 → 迁移 → composer → 版本落库 → 收尾 → 重载，
        // 失败按快照自动回滚，全程置维护标记（SystemMaintenance 中间件拦截访客与业务写请求）。
        // 此处必须吞异常：升级任务非幂等，CoreUpgradeService::run 一旦向外抛异常，
        // PluginJobConsumer 会交给队列重试，导致已覆盖过的包被重复执行。
        \app\service\QueueService::consume(
            \app\service\CoreUpgradeService::HANDLER,
            function (array $p): void {
                try {
                    \app\service\CoreUpgradeService::run($p);
                } catch (\Throwable $e) {
                    \support\Log::error('[CoreUpgrade] 升级任务收尾异常（不交队列重试，请人工核对站点状态）：' . $e->getMessage());
                }
            },
            'base'
        );

        // 启动期注册结束；此后 Registry 进入只读阶段（debug 模式违规变异抛 LogicException）
        \app\basic\RegistryGuard::enterRequestPhase();
    }
}
