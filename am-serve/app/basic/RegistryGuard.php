<?php

namespace app\basic;

/**
 * 注册中心阶段守卫（红绿灯）。
 *
 * 进程级 Registry 只允许在 worker 启动阶段（PluginBootstrapper + 插件 boot()）写入；
 * enterRequestPhase() 之后变异方法在 debug 模式下抛 LogicException，生产环境零开销。
 * 请求期合法变异只有 PluginManager 调的 forgetByOwner()（卸载/禁用清理），不经过本守卫。
 */
final class RegistryGuard
{
    private static bool $requestPhase = false;

    /** 启动阶段结束、进入请求处理阶段（由 PluginBootstrapper 在全部注册完成后调用） */
    public static function enterRequestPhase(): void
    {
        self::$requestPhase = true;
    }

    /** 变异方法入口守卫：请求阶段违规调用即抛 LogicException（仅 debug 模式生效） */
    public static function assertWritable(string $registry, string $mutator): void
    {
        if (self::$requestPhase
            && function_exists('config')
            && config('app.debug') === true) {
            throw new \LogicException(sprintf(
                '%s::%s 只能在插件 boot()（onWorkerStart 启动阶段）调用；请求期只允许 forgetByOwner()',
                $registry,
                $mutator
            ));
        }
    }
}
