<?php

namespace app\basic;

use app\exception\ApiException;
use app\interface\ServiceProviderInterface;
use support\Log;

/**
 * 服务接管注册中心
 *
 * 按接管点（key，见 ServiceProviderInterface::KEY）维护优先级链：
 * - bind()：整体覆盖实现，优先级高者胜出；
 * - extend()：装饰器包装增强，按优先级升序应用（高优先级在外层、先被调用），
 *   签名 fn(object $instance, array $params): object；
 * - make()：返回最高优先级实现；未被接管或接管实现实例化异常时回退默认实现，
 *   保证基座行为不受影响。
 *
 * 接管点（默认实现类）统一声明在 config/plugin_extend.php 的 providers 列表，
 * 由 PluginBootstrapper 在启动阶段 warmProviders() 预热。插件 bind/extend 一律写在 boot()
 * 并传 owner=插件名（匿名注册会被拒绝），请求期写注册由 RegistryGuard 在 debug 模式下拦截。
 */
class ServiceRegistry
{
    /** @var array<string, string> key => 默认实现类 FQN */
    private static array $defaults = [];

    /** @var array<string, list<array{type: string, impl: mixed, priority: int, owner: string, seq: int}>> */
    private static array $bindings = [];

    /** 注册序号（同优先级下保持注册先后顺序） */
    private static int $seq = 0;

    /** providers 声明是否已懒加载 */
    private static bool $providersLoaded = false;

    /**
     * 声明接管点：登记 KEY => 默认实现类（幂等）
     *
     * @param string $providerClass 默认实现类 FQN，须实现 ServiceProviderInterface 且覆写 KEY 常量
     */
    public static function provide(string $providerClass): void
    {
        RegistryGuard::assertWritable(self::class, 'provide');
        if (!class_exists($providerClass)
            || !is_subclass_of($providerClass, ServiceProviderInterface::class)
            || $providerClass::KEY === '') {
            throw new ApiException('服务接管点声明必须是覆写 KEY 常量的 ' . ServiceProviderInterface::class . ' 实现类：' . $providerClass);
        }
        $key = $providerClass::KEY;
        // 默认实现是「未被接管时的落点」，只能由基座 config 声明一次。
        // 后到的同名声明若放行，插件就能把回退路径整体换成自己的实现，绕过 bind 的优先级链。
        if (isset(self::$defaults[$key]) && self::$defaults[$key] !== $providerClass) {
            Log::warning("[ServiceRegistry] 接管点 {$key} 的默认实现已由基座声明为 " . self::$defaults[$key] . "，改判声明 {$providerClass} 已忽略");
            return;
        }
        self::$defaults[$key] = $providerClass;
    }

    /**
     * 覆盖接管点实现（同 key 优先级高者胜出）
     *
     * @param string $key 接管点标识
     * @param string $implClass 实现类 FQN（构造参数见 make() 的 $params）
     * @param int $priority 优先级，基座默认实现视为 0，插件覆盖须 > 0
     * @param string $owner 归属插件名（卸载/禁用对称清理用）
     */
    public static function bind(string $key, string $implClass, int $priority = 0, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'bind');
        if (self::rejectAnonymous($key, 'bind', $owner)) {
            return;
        }
        self::$bindings[$key][] = [
            'type' => 'bind',
            'impl' => $implClass,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /**
     * 包装增强接管点实现（装饰器，按优先级升序应用，高优先级在外层）
     *
     * @param string $key 接管点标识
     * @param callable $decorator fn(object $instance, array $params): object
     * @param int $priority 优先级
     * @param string $owner 归属插件名
     */
    public static function extend(string $key, callable $decorator, int $priority = 0, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'extend');
        if (self::rejectAnonymous($key, 'extend', $owner)) {
            return;
        }
        self::$bindings[$key][] = [
            'type' => 'extend',
            'impl' => $decorator,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /**
     * 解析接管点实例
     *
     * @param string $key 接管点标识
     * @param array $params 实现类构造参数（按序展开）
     * @return object 最高优先级实现（经装饰器链包装）；无覆盖返回默认实现
     */
    public static function make(string $key, array $params = []): object
    {
        self::ensureProviders();

        // 开关关闭时短路：忽略一切 bind/extend，直接回默认实现（回滚矩阵约定）
        $entries = self::enabled() ? (self::$bindings[$key] ?? []) : [];
        usort($entries, fn($a, $b) => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);

        $instance = null;
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'bind') {
                continue;
            }
            try {
                $instance = self::instantiate($entry['impl'], $params);
                break;
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[ServiceRegistry] 接管点 %s 的实现 %s 解析失败（owner=%s），回退默认实现：%s',
                    $key,
                    $entry['impl'],
                    $entry['owner'] ?: '-',
                    $e->getMessage()
                ));
            }
        }

        if ($instance === null) {
            $default = self::$defaults[$key] ?? '';
            if ($default === '' || !class_exists($default)) {
                throw new ApiException('服务接管点未注册：' . $key);
            }
            $instance = self::instantiate($default, $params);
        }

        // 装饰器按优先级升序应用（高优先级最后包装、位于外层）
        foreach (array_reverse($entries) as $entry) {
            if ($entry['type'] === 'extend') {
                $decorated = ($entry['impl'])($instance, $params);
                if (is_object($decorated)) {
                    $instance = $decorated;
                }
            }
        }

        return $instance;
    }

    /**
     * owner 是 forgetByOwner 的唯一索引：匿名 bind/extend 在插件禁用、卸载后清不掉，
     * 残留实现会继续接管全站。约定「插件注册必传插件名」，因此空 owner 一律拒绝。
     */
    private static function rejectAnonymous(string $key, string $action, string $owner): bool
    {
        if ($owner !== '') {
            return false;
        }
        Log::warning("[ServiceRegistry] 接管点 {$key} 的 {$action} 未声明 owner，注册已忽略");
        return true;
    }

    /** 接管点是否已有覆盖或增强注册 */
    public static function has(string $key): bool
    {
        return !empty(self::$bindings[$key]);
    }

    /**
     * 按 owner 清理某插件的全部 bind/extend（禁用/卸载时由 PluginManager 调用）
     */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        foreach (self::$bindings as $key => $entries) {
            $remaining = array_values(array_filter($entries, fn($entry) => $entry['owner'] !== $owner));
            if ($remaining) {
                self::$bindings[$key] = $remaining;
            } else {
                unset(self::$bindings[$key]);
            }
        }
    }

    /** 实例化实现类（构造参数按序展开） */
    private static function instantiate(string $class, array $params): object
    {
        if (!class_exists($class)) {
            throw new ApiException('服务实现类不存在：' . $class);
        }
        return $params ? new $class(...array_values($params)) : new $class();
    }

    /** 懒加载 config/plugin_extend.php 声明的接管点（幂等；裸 CLI 无配置时静默跳过） */
    private static function ensureProviders(): void
    {
        if (self::$providersLoaded) {
            return;
        }
        self::$providersLoaded = true;
        if (!function_exists('config')) {
            return;
        }
        foreach ((array)config('plugin_extend.providers', []) as $providerClass) {
            self::provide($providerClass);
        }
    }

    /**
     * 启动期预热：加载 config/plugin_extend.php 声明的接管点。
     * 由 PluginBootstrapper 在 boot 阶段调用，把默认实现登记移出请求期
     * （请求期 make() 内若触发 provide() 会撞 RegistryGuard 断言）。
     */
    public static function warmProviders(): void
    {
        self::ensureProviders();
    }

    /** 特性开关（config/plugin_extend.php；非 webman 环境视为开启，便于脚本校验） */
    private static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.service_enabled', true) : true;
    }
}
