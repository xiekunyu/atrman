<?php

namespace app\basic;

use app\cache\ConfigCache;
use app\cache\DictCache;
use app\cache\ReflectionCache;
use app\cache\UserAuthCache;
use app\cache\UserMenuCache;
use app\exception\ApiException;
use app\model\system\Config;
use app\model\system\Dict;
use app\model\system\Menu;
use app\model\system\Remote;
use app\model\system\RoleMenu;
use app\service\PermissionScanner;
use support\Log;

/**
 * 插件声明式注册器：运行期把插件声明的菜单 / 权限按钮 / 配置 Schema / 字典 / 远程数据源幂等落库
 *
 * 约定：
 * - 一切声明必须携带 app_name = 插件名；菜单/配置/权限卸载时按 app_name 物理删除（沿用既有流程）；
 * - 例外：dict()/remoteData() 播种的是全局共享资产，不带归属、卸载不回收（避免冗余与互相踩踏，
 *   幂等只增不改）；
 * - 菜单 code 不得占用保留前缀 app:entry:*（该前缀归插件"管理入口"安全网，由 PluginManager::syncEntry 维护）；
 * - 权限 slug 冒号格式 {module}:{action}:{operation}，建议以插件名为 module；
 * - 与 install.sql 静态路径并存：声明式 API 为增量，幂等不冲突（命中则更新、缺失则插入）；
 * - 落库后清对应缓存（UserMenuCache/UserAuthCache/ConfigCache/DictCache）。
 *
 * 调用时机：install/enable/upgrade 钩子或后台手动同步。菜单类声明幂等可重复调用；
 * 权限扫描（syncPermissionsFromControllers）有整表读取开销，禁止放进 boot()。
 */
class PluginRegistrar
{
    /** 插件"管理入口"保留 code 前缀（禁止声明占用） */
    private const RESERVED_CODE_PREFIX = 'app:entry:';

    /**
     * 声明式注册菜单（幂等：按 code 命中则同步字段，缺失则插入）
     *
     * @param array $def 菜单定义：
     *   必填 app_name（插件名）、name（显示名）、code（全局唯一标识）；
     *   可选 pid/type(默认2菜单)/path/component/icon/sort/is_hide/is_iframe/status 等 Menu 表字段；
     *   可选 parent_code（父菜单 code，解析为 pid，父菜单须属同插件或基座）
     */
    public static function menu(array $def): void
    {
        [$appName, $name, $code] = self::requireKeys($def, ['app_name', 'name', 'code']);
        if (str_starts_with($code, self::RESERVED_CODE_PREFIX)) {
            throw new ApiException('菜单 code 不允许占用保留前缀 ' . self::RESERVED_CODE_PREFIX . '：' . $code);
        }

        $attributes = [
            'app_name' => $appName,
            'name' => $name,
            'pid' => self::resolvePid($def, $appName),
            'type' => (int)($def['type'] ?? 2),
            'path' => (string)($def['path'] ?? ''),
            'component' => (string)($def['component'] ?? ''),
            'icon' => (string)($def['icon'] ?? ''),
            'sort' => (int)($def['sort'] ?? 100),
            'is_hide' => (int)($def['is_hide'] ?? 0),
            'is_cache' => (int)($def['is_cache'] ?? 0),
            'is_fixed' => (int)($def['is_fixed'] ?? 0),
            'is_full_page' => (int)($def['is_full_page'] ?? 0),
            'is_iframe' => (int)($def['is_iframe'] ?? 0),
            'status' => (int)($def['status'] ?? 1),
        ];

        $menu = Menu::where('code', $code)->find();
        if ($menu) {
            // code 已被其它归属占用：不越权改写，仅告警（冲突排查靠 owner 可回溯）
            if ((string)$menu->app_name !== $appName && $menu->app_name !== '') {
                Log::warning('[PluginRegistrar] 菜单 code 冲突，跳过：' . $code . '（归属 ' . $menu->app_name . '，声明者 ' . $appName . '）');
                return;
            }
            if (self::attributesEqual($menu, $attributes)) {
                return; // 无变化：不写库、不清缓存
            }
            $menu->save($attributes);
        } else {
            Menu::create($attributes + ['code' => $code]);
        }
        self::clearMenuAuthCaches();
    }

    /**
     * 声明式注册权限按钮节点（幂等：按 slug 命中则同步，缺失则插入 type=3 按钮）
     *
     * @param array $def 权限定义：
     *   必填 app_name（插件名）、title（按钮名）、slug（冒号格式权限标识，全局唯一）；
     *   可选 pid / parent_code（挂载的父菜单）
     */
    public static function permission(array $def): void
    {
        [$appName, $title, $slug] = self::requireKeys($def, ['app_name', 'title', 'slug']);

        $note = null;
        $outcome = self::writePermission($appName, [
            'app_name' => $appName,
            'name' => $title,
            'slug' => $slug,
            'pid' => self::resolvePid($def, $appName),
            'type' => 3,
            'sort' => (int)($def['sort'] ?? 100),
            'status' => (int)($def['status'] ?? 1),
        ], $note);
        if (in_array($outcome, ['created', 'updated'], true)) {
            self::clearMenuAuthCaches();
        }
    }

    /**
     * 扫描控制器自动派生按钮权限节点（幂等，可重复调用）。
     *
     * 只负责按钮层（type=3），目录与页面由使用者在菜单里自行维护：
     * 反射读 #[Permission] 的 slug/title 与路由注解的请求方式，与库里归属本 app_name 的
     * type=3 行做 diff。slug 是鉴权唯一键（见 AuthCheck），库里缺一行，非超管就永久 403。
     *
     * - 父页面解析不出来的 slug 计入 unattached 并跳过，不建 pid=0 的野按钮；页面须先于本方法存在。
     * - 孤儿（库里有、代码里没有）默认只报告不删：删按钮行会连带回收 role_menu 授权。
     * - 整轮只在真有写入时清一次缓存，避免逐行 clear 放大成 N 次全量失效。
     *
     * @param string $appName 归属：插件名；扫描基座控制器传 ''
     * @param array  $targets 控制器目录或 FQCN 列表
     * @param array  $options {
     *     page_map: array<string,string> 控制器类名（FQCN 或短名）=> 父页面 code，
     *                            约定推不出归属时用它显式指定（如子功能挂在宿主页面下）
     *     prune:    bool 默认 false，true 才删除孤儿行并连带清理 role_menu 授权
     * }
     * @return array{
     *   created: string[], updated: string[], unchanged: int, orphan: array[],
     *   unattached: array[], ambiguous: array[], conflict: array[], removed: string[],
     *   revoked: array, warnings: string[], unannotated: string[]
     * }
     */
    public static function syncPermissionsFromControllers(string $appName, array $targets, array $options = []): array
    {
        $pageMap = (array)($options['page_map'] ?? []);
        $prune = (bool)($options['prune'] ?? false);

        $scan = PermissionScanner::scan($targets);

        // 候选父页面：只看本归属（基座为空串）的菜单行；入口行由 syncEntry 维护，不参与挂载
        $pages = Menu::where('app_name', $appName)
            ->where('type', 2)
            ->field('id,pid,name,code,path')
            ->select()
            ->toArray();

        $result = [
            'created' => [], 'updated' => [], 'unchanged' => 0,
            'orphan' => [], 'unattached' => [], 'ambiguous' => [],
            'conflict' => [], 'removed' => [], 'revoked' => [],
            // 派生阶段本身的异常：写了注解没写 slug、同 slug 被两个方法用、注解参数写错、类加载不了、有路由却没有鉴权注解
            'warnings' => array_merge($scan['empty_slug'], $scan['duplicate_slug'], $scan['attr_error'], $scan['missing_class']),
            'unannotated' => $scan['unannotated'],
        ];
        $slugsInCode = [];

        foreach ($scan['nodes'] as $slug => $node) {
            $slugsInCode[] = $slug;
            $pid = self::resolveParentPage($slug, $node, $pages, $pageMap, $result);
            if ($pid === null) {
                continue;
            }

            $attributes = [
                'app_name' => $appName,
                'name' => $node['name'],
                'slug' => $slug,
                'pid' => $pid,
                'type' => 3,
                'method' => $node['method'],
                'sort' => $node['sort'],
                'status' => 1,
            ];

            $note = null;
            $outcome = self::writePermission($appName, $attributes, $note);
            if ($note !== null) {
                $result['conflict'][] = $note;
            }
            if ($outcome === 'created') {
                $result['created'][] = $slug;
            } elseif ($outcome === 'updated') {
                $result['updated'][] = $slug;
            } elseif ($outcome === 'unchanged') {
                $result['unchanged']++;
            }
        }

        // 孤儿（库里有、代码已无）默认只报告，prune=true 才删；扫描结果为空时显然是读取失败而非真删光
        $orphans = $scan['nodes'] === [] ? [] : Menu::where('app_name', $appName)
            ->where('type', 3)
            ->where('slug', '<>', '')
            ->whereNotIn('slug', $slugsInCode)
            ->field('id,name,slug')
            ->select();
        foreach ($orphans as $orphan) {
            $result['orphan'][] = ['id' => (int)$orphan->id, 'slug' => (string)$orphan->slug, 'name' => (string)$orphan->name];
            if (!$prune) {
                continue;
            }
            $grants = RoleMenu::where('menu_id', (int)$orphan->id)->column('role_id');
            RoleMenu::where('menu_id', (int)$orphan->id)->delete();
            $orphan->delete();
            $result['removed'][] = (string)$orphan->slug;
            $result['revoked'][] = ['slug' => (string)$orphan->slug, 'role_ids' => $grants];
        }

        if (count($result['created']) || count($result['updated']) || count($result['removed'])) {
            // AuthCheck 读的是按 类::方法 进程内备忘的 #[Permission]，改过注解后不清它，库里换了 slug 也照样按旧 slug 鉴权
            ReflectionCache::clear();
            self::clearMenuAuthCaches();
            Log::info('[PluginRegistrar] 按钮权限同步 ' . ($appName !== '' ? $appName : '基座') . '：'
                . json_encode([
                    'created' => count($result['created']),
                    'updated' => count($result['updated']),
                    'removed' => count($result['removed']),
                    'orphan' => count($result['orphan']),
                    'unattached' => count($result['unattached']),
                ], JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    /** 菜单树与按钮权限都按用户维度缓存，声明变更后必须成对失效，只清一个会出现菜单变了但按钮还是旧权限 */
    private static function clearMenuAuthCaches(): void
    {
        UserMenuCache::clear();
        UserAuthCache::clear();
    }

    /**
     * 解析按钮挂载页面：page_map 显式指定优先，其次按 slug 段与页面 code/path 末段匹配。
     * 结果写进 $result 的 unattached / ambiguous / conflict，返回 null 表示本行跳过。
     */
    private static function resolveParentPage(string $slug, array $node, array $pages, array $pageMap, array &$result): ?int
    {
        $shortName = substr(strrchr('\\' . $node['controller'], '\\'), 1);
        $explicit = $pageMap[$node['controller']] ?? $pageMap[$shortName] ?? null;
        if (is_scalar($explicit)) {
            $parentId = self::pageIdByCode((string)$explicit, $pages);
            if ($parentId === 0) {
                $result['conflict'][] = ['slug' => $slug, 'reason' => 'page_map 指定的父页面不存在：' . $explicit];
                return null;
            }
            return $parentId;
        }

        $matched = PermissionScanner::matchPage((string)$node['segment'], $pages);
        if ($matched['status'] === 'resolved') {
            return (int)$matched['pid'];
        }
        if ($matched['status'] === 'ambiguous') {
            $result['ambiguous'][] = ['slug' => $slug, 'segment' => $node['segment'], 'page_ids' => $matched['candidates']];
            return null;
        }
        $result['unattached'][] = ['slug' => $slug, 'segment' => $node['segment']];
        return null;
    }

    /**
     * 声明式注册配置组与字段 Schema（幂等；字段已填 value 不覆盖，仅同步 schema）
     *
     * 落库结构与后台「配置管理」完全同构（ConfigController 保存路径），前端
     * buildFormConfig 可直接渲染，插件由此获得"声明即有后台配置表单"。
     *
     * @param array $def 配置 Schema 定义：
     *   app_name  插件名（必填）
     *   group     ['code' => 配置组编码（全局唯一，如 'my_sms'）, 'name' => 显示名]（必填）
     *   fields    字段列表，每项与配置设计器 item 同构：
     *             ['name' => 显示名, 'key' => 字段编码, 'type' => 表单类型(input/password/number/select/switch...),
     *              'defaultValue' => 默认值, 其余键（placeholder/options 等）原样存入 options 供前端渲染]
     */
    public static function configSchema(array $def): void
    {
        $appName = (string)($def['app_name'] ?? '');
        $group = (array)($def['group'] ?? []);
        $fields = (array)($def['fields'] ?? []);
        if ($appName === '' || empty($group['code']) || empty($group['name'])) {
            throw new ApiException('configSchema 声明必须携带 app_name 与 group.code/group.name');
        }

        $groupCode = (string)$group['code'];
        $groupRow = Config::where('code', $groupCode)->where('pid', 0)->find();
        if ($groupRow) {
            if ((string)$groupRow->app_name !== $appName && $groupRow->app_name !== '') {
                Log::warning('[PluginRegistrar] 配置组 code 冲突，跳过：' . $groupCode . '（归属 ' . $groupRow->app_name . '，声明者 ' . $appName . '）');
                return;
            }
            $groupRow->save(['name' => (string)$group['name'], 'app_name' => $appName]);
        } else {
            $groupRow = Config::create([
                'pid' => 0,
                'name' => (string)$group['name'],
                'code' => $groupCode,
                'app_name' => $appName,
            ]);
        }

        $groupId = (int)$groupRow->id;
        foreach ($fields as $sort => $item) {
            if (empty($item['key']) || empty($item['name'])) {
                continue;
            }
            $schema = [
                'pid' => $groupId,
                'name' => (string)$item['name'],
                'sort' => (int)($item['sort'] ?? $sort),
                'form_type' => (string)($item['type'] ?? 'input'),
                'options' => $item,
            ];
            $row = Config::where('pid', $groupId)->where('code', (string)$item['key'])->find();
            if ($row) {
                // 已填 value（如后台录入的凭证）不覆盖，仅同步 schema
                $row->save($schema);
            } else {
                Config::create($schema + [
                    'code' => (string)$item['key'],
                    'value' => $item['defaultValue'] ?? '',
                ]);
            }
        }
        ConfigCache::clearConfig($groupCode, $appName);
    }

    /**
     * 声明式注册数据字典（幂等播种；配置约定见类注释，但字典/远程数据是全局资产：
     * 不带 app_name 归属、卸载不回收，避免插件在共享表里堆冗余数据）
     *
     * 语义：
     * - 根节点按 code 命中即复用（只记名称漂移告警，不覆盖运营改过的 name）；
     * - 字典项按 value 命中即跳过——已存在项的 name/color/sort/status 永不回写，
     *   管理员在「字典管理」里的定制不会被插件升级冲掉；被软删的项声明会复活；
     * - 有新增/复活才清 DictCache，重复调用零开销。
     *
     * @param array $def 字典定义：
     *   必填 app_name（来源插件，写进根节点 remark 便于溯源）、code（字典编码）、
     *   items（[['value'=>, 'name'=>, 可选 color/sort/remark]...]）；
     *   可选 name（根节点显示名，仅首建时写入，默认取 code）
     */
    public static function dict(array $def): void
    {
        [$appName, $code] = self::requireKeys($def, ['app_name', 'code']);
        $items = (array)($def['items'] ?? []);
        $rootName = (string)($def['name'] ?? $code);
        if ($code === '' || $items === []) {
            throw new ApiException('dict 声明必须携带 code 与非空 items');
        }

        $changed = false;
        $root = Dict::where('code', $code)->where('pid', 0)->find();
        if (!$root) {
            $root = Dict::create([
                'pid' => 0,
                'code' => $code,
                'name' => $rootName,
                'remark' => '由插件 ' . $appName . ' 装配',
                'status' => 1,
            ]);
            $changed = true;
        } elseif ((string)$root->name !== $rootName) {
            Log::info('[PluginRegistrar] 字典 ' . $code . ' 已存在（名称「' . $root->name . '」≠ 声明「' . $rootName . '」），按既有为准');
        }
        $rootId = (int)$root->id;

        foreach ($items as $item) {
            $value = (string)($item['value'] ?? '');
            $name = (string)($item['name'] ?? '');
            if ($value === '' || $name === '') {
                continue;
            }
            $exist = Dict::withTrashed()->where('pid', $rootId)->where('value', $value)->find();
            if ($exist && !$exist->delete_time) {
                continue;
            }
            if ($exist) {
                // 软删复活：只恢复可见性，不覆盖运营改过的字段
                $exist->restore();
            } else {
                Dict::create([
                    'pid' => $rootId,
                    'name' => $name,
                    'value' => $value,
                    'color' => (string)($item['color'] ?? ''),
                    'sort' => (int)($item['sort'] ?? 0),
                    'remark' => (string)($item['remark'] ?? ''),
                    'status' => 1,
                ]);
            }
            $changed = true;
        }

        if ($changed) {
            DictCache::clear();
        }
    }

    /**
     * 声明式注册远程数据源（幂等；全局资产，卸载不回收，语义同 dict）
     *
     * - code 未占用 → 建行（is_sys=0，运营可改可删）；
     * - 已存在且 is_sys=0 → 只同步"契约字段"（api_url/method/type/三字段映射/header/params），
     *   name 与 status 归运营不回写——插件升级接口形态时消费端自动跟上；
     * - 已存在且 is_sys=1（基座内置）→ 跳过并告警，绝不覆盖。
     *
     * @param array $def 数据源定义：
     *   必填 app_name（来源插件，仅告警定位用）、code、api_url；
     *   可选 name/method(1get,2post)/type(1列表,2树)/label_field/value_field/
     *   children_field（表列名拼写为 chidlren_field，此处兼容两种键）/header/params/sort 不参与
     */
    public static function remoteData(array $def): void
    {
        [$appName, $code, $apiUrl] = self::requireKeys($def, ['app_name', 'code', 'api_url']);

        $schema = [
            'api_url' => (string)$apiUrl,
            'method' => (int)($def['method'] ?? 1),
            'type' => (int)($def['type'] ?? 1),
            'label_field' => (string)($def['label_field'] ?? 'name'),
            'value_field' => (string)($def['value_field'] ?? 'value'),
            'chidlren_field' => (string)($def['children_field'] ?? $def['chidlren_field'] ?? ''),
            'header' => (array)($def['header'] ?? []),
            'params' => (array)($def['params'] ?? []),
        ];

        $row = Remote::where('code', $code)->find();
        if (!$row) {
            Remote::create($schema + [
                'code' => $code,
                'name' => (string)($def['name'] ?? $code),
                'is_sys' => 0,
                'status' => 1,
            ]);
            return;
        }
        if ((int)$row->is_sys === 1) {
            Log::warning('[PluginRegistrar] 远程数据源 code 与内置冲突，跳过：' . $code . '（声明方 ' . $appName . '）');
            return;
        }
        // 契约字段同步；name/status 留给运营
        $row->save($schema);
    }

    // 卸载清理不提供 forgetByOwner：菜单/权限/配置组均带 app_name，
    // 既有卸载流程 DatabaseService::deletePluginDatabaseInfo 已按 app_name 物理删除。

    /** 校验必填键并返回其值 */
    private static function requireKeys(array $def, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = trim((string)($def[$key] ?? ''));
            if ($value === '') {
                throw new ApiException('声明缺少必填项：' . $key);
            }
            $values[] = $value;
        }
        return $values;
    }

    /** 解析父菜单 id：优先 parent_code（须属同插件或基座菜单），其次 pid，默认 0 */
    private static function resolvePid(array $def, string $appName): int
    {
        if (!empty($def['parent_code'])) {
            $parent = Menu::where('code', (string)$def['parent_code'])->find();
            if (!$parent) {
                throw new ApiException('父菜单不存在：' . $def['parent_code']);
            }
            if (!in_array((string)$parent->app_name, ['', $appName], true)) {
                throw new ApiException('不允许挂载到其它插件的菜单下：' . $def['parent_code']);
            }
            return (int)$parent->id;
        }
        return (int)($def['pid'] ?? 0);
    }

    /** 在候选页面行中按 code 精确取父页面 id（忽略大小写），取不到返回 0 */
    private static function pageIdByCode(string $code, array $pages): int
    {
        foreach ($pages as $page) {
            if (strcasecmp((string)($page['code'] ?? ''), $code) === 0) {
                return (int)$page['id'];
            }
        }
        return 0;
    }

    /**
     * 按钮节点落库（幂等）：按 slug + type=3 命中则同步，缺失则插入。
     *
     * @param array|null $note 带出跳过原因（归属冲突/被非按钮行占用/重复行）
     * @return string created|updated|unchanged|skipped
     */
    private static function writePermission(string $appName, array $attributes, ?array &$note = null): string
    {
        $slug = (string)$attributes['slug'];
        // slug 无唯一索引，历史脏数据可能有多行：只认最小 id，多余行进报告，绝不再插第三行
        $rows = Menu::where('slug', $slug)->where('type', 3)->order('id asc')->select();
        if (count($rows) > 1) {
            $note = ['slug' => $slug, 'reason' => '同 slug 存在多行按钮节点，已按最小 id 同步，多余行需人工清理', 'extra_ids' => array_slice($rows->column('id'), 1)];
        }
        $node = $rows->first();
        if (!$node) {
            $occupied = Menu::where('slug', $slug)->where('type', '<>', 3)->find();
            if ($occupied) {
                $note = ['slug' => $slug, 'reason' => 'slug 已被非按钮菜单行占用（归属 ' . $occupied->app_name . '，type=' . $occupied->type . '），不再建行'];
                return 'skipped';
            }
            Menu::create($attributes + ['code' => $slug]);
            return 'created';
        }
        if ((string)$node->app_name !== $appName && $node->app_name !== '') {
            $note = ['slug' => $slug, 'reason' => '权限 slug 冲突（归属 ' . $node->app_name . '，声明者 ' . $appName . '）'];
            Log::warning('[PluginRegistrar] 权限 slug 冲突，跳过：' . $slug . '（归属 ' . $node->app_name . '，声明者 ' . $appName . '）');
            return 'skipped';
        }
        if (self::attributesEqual($node, $attributes)) {
            return 'unchanged';
        }
        $node->save($attributes);
        return 'updated';
    }

    /** 判断模型行与目标属性是否完全一致（避免 boot 幂等重放时反复写库/清缓存） */
    private static function attributesEqual(Menu $menu, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ((string)$menu->$key !== (string)$value) {
                return false;
            }
        }
        return true;
    }
}
