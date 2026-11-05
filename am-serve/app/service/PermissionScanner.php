<?php

namespace app\service;

use app\basic\BaseController;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use support\annotation\route\Route as RouteAttribute;

/**
 * 按钮权限扫描器：反射读取控制器的 #[Permission] 与路由注解，派生 type=3 按钮节点定义。
 *
 * 纯派生、不碰数据库，也不写死落库策略——写库由 PluginRegistrar::syncPermissionsFromControllers 负责，
 * 因此同一份派生规则既能被线上同步调用，也能被离线校验脚本直接比对（见 scanNodes 的返回结构）。
 *
 * 派生边界（有意为之，勿"顺手补全"）：
 * - 只认方法级 #[Permission] 且 slug 非空：AuthCheck 对空 slug 直接放行，无注解的方法是刻意公开的接口，
 *   扫描器给它们造权限等于上线即锁死；这类方法只进报告，不生成节点。
 * - 只认本类声明的 public 方法：initialize() 等继承方法、魔术方法一律跳过。
 * - 不派生目录/页面（type=1/2）：component、icon、层级关系无法从控制器推出，由使用者在菜单里自己加。
 * - 同 slug 允许多个接口共用（预检、进度查询这类附属接口不单独设权限）：节点按「正主」接口生成，
 *   共用方一律进 duplicate_slug 报告——一个权限放开几个接口，必须让管理员看得见。
 */
class PermissionScanner
{
    /**
     * 收集目录下的控制器类名（递归，按文件名 *Controller.php）。
     *
     * 命名空间直接从文件首部解析，不依赖 PSR-4 目录映射——插件与基座的 autoload 根不同，
     * 靠路径猜 FQCN 会在插件目录改名时静默失效。
     *
     * @return string[] FQCN 列表（已排序，保证扫描顺序稳定）
     */
    public static function controllersIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }
            $fqcn = self::classOf($file->getPathname());
            if ($fqcn !== null) {
                $classes[] = $fqcn;
            }
        }
        sort($classes);
        return $classes;
    }

    /** 由文件路径解析类的全限定名 */
    public static function classOf(string $file): ?string
    {
        $source = (string)file_get_contents($file);
        if (!preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $match)) {
            return null;
        }
        $shortName = basename($file, '.php');
        return rtrim($match[1], '\\') . '\\' . $shortName;
    }

    /**
     * 扫描控制器，派生按钮节点定义。
     *
     * @param array $targets 控制器目录或 FQCN 混合列表
     * @return array{
     *   nodes: array<string, array>,      // slug => 派生定义
     *   empty_slug: string[],             // 写了 #[Permission] 但没写 slug：大概率是漏填
     *   unannotated: string[],            // 有路由但没有权限注解：刻意公开或漏写，仅供人工核对
     *   missing_class: string[],          // 文件里解析出的类无法加载
     *   multi_route: string[],            // 一个方法挂多种请求方式，method 只能存一个
     *   duplicate_slug: string[],         // 多个接口共用一个 slug：按「正主」接口生成节点，其余只是共用这项授权
     *   attr_error: string[]              // 注解实例化失败
     * }
     */
    public static function scan(array $targets): array
    {
        $report = [
            'nodes' => [],
            'empty_slug' => [],
            'unannotated' => [],
            'missing_class' => [],
            'multi_route' => [],
            'duplicate_slug' => [],
            'attr_error' => [],
        ];
        // slug => 声明了它的所有接口；一个 slug 只能落一行，扫描结束后再定由谁生成节点
        $candidates = [];

        foreach (self::normalizeTargets($targets) as $fqcn) {
            if (!class_exists($fqcn)) {
                $report['missing_class'][] = $fqcn;
                continue;
            }
            $class = new ReflectionClass($fqcn);
            if ($class->isAbstract() || !$class->isSubclassOf(BaseController::class)) {
                continue;
            }
            // 公开接口白名单里的方法不参与派生（与 AuthCheck 的放行顺序保持一致）
            $noNeedLogin = (array)($class->getDefaultProperties()['noNeedLogin'] ?? []);

            $sort = 0;
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $action = $method->getName();
                if (str_starts_with($action, '__') || $method->isStatic()) {
                    continue;
                }
                // 只认本类声明的方法：基类的 initialize()/公用方法不该被记成每个控制器的按钮
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (in_array($action, $noNeedLogin, true)) {
                    continue;
                }

                $attributes = $method->getAttributes(Permission::class);
                if (empty($attributes)) {
                    if (self::routeMethods($method)) {
                        $report['unannotated'][] = $fqcn . '::' . $action;
                    }
                    continue;
                }
                $permission = self::instantiate($attributes[0]);
                if ($permission === null) {
                    $report['attr_error'][] = $fqcn . '::' . $action;
                    continue;
                }
                $slug = trim((string)$permission->getSlug());
                if ($slug === '') {
                    $report['empty_slug'][] = $fqcn . '::' . $action;
                    continue;
                }

                $sort++;
                $methods = self::routeMethods($method);
                if (count($methods) > 1) {
                    $report['multi_route'][] = $fqcn . '::' . $action . ' (' . implode('/', $methods) . ')';
                }
                $title = trim((string)$permission->getTitle());

                $candidates[$slug][] = [
                    'slug' => $slug,
                    // slug 兜底名：后台菜单树里宁可看到原始标识，也不要空行
                    'name' => $title !== '' ? $title : $slug,
                    'method' => $methods[0] ?? '',
                    // 与既有种子数据一致：按类内声明顺序从 1 递增，重排方法即产生一次 updated
                    'sort' => $sort,
                    'controller' => $fqcn,
                    'action' => $action,
                    'segment' => self::pageSegment($slug),
                ];
            }
        }

        foreach ($candidates as $slug => $group) {
            $owner = $group[0];
            if (count($group) > 1) {
                // 同 slug 只落一行。方法名与 slug 末段一致的那个是「正主」，由它决定节点名/请求方式；
                // 其余方法只是共用这一项授权（预检、进度查询之类），报告里必须把它们列全，
                // 否则勾一个权限实际放开了几个接口无人知晓。
                $colon = strrpos($slug, ':');
                $tail = strtolower($colon === false ? $slug : substr($slug, $colon + 1));
                foreach ($group as $candidate) {
                    if (strtolower($candidate['action']) === $tail) {
                        $owner = $candidate;
                        break;
                    }
                }
                $others = array_values(array_filter($group, fn(array $node): bool => $node !== $owner));
                $report['duplicate_slug'][] = $slug . '：节点按 ' . self::endpoint($owner) . ' 生成，另由 '
                    . implode('、', array_map(self::endpoint(...), $others)) . ' 共用';
            }
            $report['nodes'][$slug] = $owner;
        }

        return $report;
    }

    /** 报告里定位一个接口：类名::方法名 */
    private static function endpoint(array $node): string
    {
        return $node['controller'] . '::' . $node['action'];
    }

    /**
     * 从 slug 取「页面段」：去掉 module 首段与 operation 末段，用于反查挂载页面。
     *
     * {module}:{page}:{operation} => page；{module}:{page}:{sub}:{operation} => page:sub
     * （多段 slug 无法靠约定判定归属，交给调用方的 page_map 显式指定）
     */
    public static function pageSegment(string $slug): string
    {
        $parts = explode(':', $slug);
        if (count($parts) <= 2) {
            return $slug;
        }
        return implode(':', array_slice($parts, 1, -1));
    }

    /**
     * 在候选页面行里为 slug 找父页面（纯函数，便于离线用 seed.sql 的行做验证）。
     *
     * 匹配阶梯按「越具体越优先」：页面 code 末段 => 路由 path 末段，每级只有唯一命中才采用；
     * 命中多行或全部落空则由调用方决定报告与跳过，绝不猜一个挂上去——
     * 按钮挂错父级会直接改变谁能看到它。
     *
     * @param string $segment 页面段
     * @param array  $pages   候选页面行，每行含 id/code/path
     * @return array{pid: int, status: string, candidates: int[]}
     */
    public static function matchPage(string $segment, array $pages): array
    {
        $target = strtolower($segment);
        if ($target === '') {
            return ['pid' => 0, 'status' => 'missing', 'candidates' => []];
        }

        $levels = [
            'code' => fn(array $page): string => self::token((string)($page['code'] ?? '')),
            'path' => fn(array $page): string => self::token((string)($page['path'] ?? '')),
        ];
        foreach ($levels as $read) {
            $hits = [];
            foreach ($pages as $page) {
                if ($read($page) === $target) {
                    $hits[] = (int)$page['id'];
                }
            }
            if (count($hits) === 1) {
                return ['pid' => $hits[0], 'status' => 'resolved', 'candidates' => $hits];
            }
            if (count($hits) > 1) {
                return ['pid' => 0, 'status' => 'ambiguous', 'candidates' => $hits];
            }
        }

        return ['pid' => 0, 'status' => 'missing', 'candidates' => []];
    }

    /** 取路径/标识的最后一段并小写化：/system/role => role，am_member:member => member */
    private static function token(string $value): string
    {
        $segments = preg_split('#[/:]#', trim($value, '/'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return strtolower((string)end($segments));
    }

    /** 读取方法上的路由注解，返回去重后的 HTTP 方法列表 */
    private static function routeMethods(ReflectionMethod $method): array
    {
        $found = [];
        // 不用 IS_INCLUDE_INHERITED / isInstanceOf（均 PHP 8.4 才有），Get/Post 等继承 Route，按类名判定
        foreach ($method->getAttributes() as $attribute) {
            if (!is_a($attribute->getName(), RouteAttribute::class, true)) {
                continue;
            }
            $route = self::instantiate($attribute);
            if ($route === null) {
                continue;
            }
            foreach ((array)$route->methods as $httpMethod) {
                $httpMethod = strtoupper((string)$httpMethod);
                if ($httpMethod !== '' && !in_array($httpMethod, $found, true)) {
                    $found[] = $httpMethod;
                }
            }
        }
        return $found;
    }

    /** 注解实例化：属性写错参数时不能让整轮扫描崩掉 */
    private static function instantiate(ReflectionAttribute $attribute): ?object
    {
        try {
            return $attribute->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    /** 目标归一化：目录展开为类名，类名原样保留 */
    private static function normalizeTargets(array $targets): array
    {
        $classes = [];
        foreach ($targets as $target) {
            $target = (string)$target;
            if ($target === '') {
                continue;
            }
            if (is_dir($target)) {
                $classes = array_merge($classes, self::controllersIn($target));
            } else {
                $classes[] = $target;
            }
        }
        return array_values(array_unique($classes));
    }
}
