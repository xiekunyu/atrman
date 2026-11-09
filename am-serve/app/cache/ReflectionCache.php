<?php

namespace app\cache;

use ReflectionClass;
use ReflectionMethod;
use app\service\Permission;

/**
 * 控制器注解缓存。
 *
 * 只放进程内备忘，不写 Redis：注解随代码变更，写 Redis（此前 expire 一年）会让部署后的新
 * #[Permission] 最长陈旧一年——slug 在库里缺一条，非超管就永久 403。
 */
class ReflectionCache
{
    /**
     * 进程内备忘 TTL（秒）。
     * clear() 只能清当前 worker，而插件装卸、按钮同步、脚手架重生成都是由某个 worker 处理 HTTP
     * 请求时调用的；不给过期时间，其余 worker 就要到下次重启才收敛。5s 兜底跨 worker 感知，
     * 与 PluginStatusCheck::CACHE_TTL 同一口径。
     */
    private const MEMO_TTL = 5;

    /** 键空间为路由级 controller/action（代码级有限枚举），cap 仅作内存兜底 */
    private const MEMO_CAP = 1024;

    private static ?TtlMemo $memo = null;

    private static function memo(): TtlMemo
    {
        return self::$memo ??= new TtlMemo(self::MEMO_CAP, self::MEMO_TTL);
    }

    public static function getNoNeedLogin(string $controller): array
    {
        return self::memo()->remember('no_need' . "\0" . $controller, fn() => self::readNoNeedLogin($controller));
    }

    public static function getPermissionAttributes(string $controller, string $action): array
    {
        $key = 'permission' . "\0" . $controller . '::' . $action;
        return self::memo()->remember($key, fn() => self::readPermission($controller, $action));
    }

    /** 代码或插件启停变更后重置本进程（其余 worker 由 MEMO_TTL 兜底）。 */
    public static function clear(): void
    {
        self::$memo?->flush();
    }

    private static function readNoNeedLogin(string $controller): array
    {
        if (!class_exists($controller)) {
            return [];
        }
        return (new ReflectionClass($controller))->getDefaultProperties()['noNeedLogin'] ?? [];
    }

    private static function readPermission(string $controller, string $action): array
    {
        if (!class_exists($controller) || !method_exists($controller, $action)) {
            return [];
        }
        $attributes = (new ReflectionMethod($controller, $action))->getAttributes(Permission::class);
        if (empty($attributes)) {
            return [];
        }
        $attr = $attributes[0]->newInstance();
        return [
            'title' => $attr->getTitle(),
            'slug' => $attr->getSlug(),
        ];
    }
}
