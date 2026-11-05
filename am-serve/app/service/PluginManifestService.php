<?php

namespace app\service;

use app\exception\ApiException;
use app\model\plugin\App as Plugin;

class PluginManifestService
{
    protected const MANIFEST_DB_FIELDS = [
        'name', 'title', 'icon', 'type', 'version', 'description', 'author',
        'path', 'entry', 'manage_entry', 'open_mode',
        'is_custom', 'is_core', 'source', 'scope', 'platform', 'is_dev',
    ];

    protected const INT_MANIFEST_DB_FIELDS = [
        'type', 'is_custom', 'is_core', 'source', 'scope', 'platform', 'is_dev',
    ];

    /** 应用/插件标识格式：以 am_ 开头，仅小写字母、数字、下划线（首字符为字母）。 */
    public const NAME_PATTERN = '/^am_[a-z][a-z0-9_]*$/';

    /** am_ 前缀之后自定义命名部分的最小长度（不含 am_ 前缀的 3 个字符）。 */
    public const MIN_NAME_SUFFIX_LEN = 5;

    /**
     * 基座兜底保留标识：仅内置与基座运行强相关、绝对不能撞名的核心名。
     * 应用标识的完整权威保留清单由应用中心（am_store 插件）维护，基座不依赖插件；
     * 此清单仅在应用市场不可达（fail-open）时作为兜底拦截，避免撞基座核心模块。
     */
    public const RESERVED_NAMES = [
        'am_system', 'am_app', 'am_admin', 'am_index', 'am_public', 'am_api',
        'am_core', 'am_base', 'am_main', 'am_common', 'am_framework', 'am_plugin',
    ];

    /**
     * 官方分发标识：由本站作者签发、经应用市场分发的插件，本地安装包也必须能在官方发布清单里查到对应版本。
     * 不含 am_demo（模板/示例包，允许自行改码后本地安装）。
     */
    public const OFFICIAL_NAMES = ['am_store', 'am_member', 'am_aibase'];

    /** 保存插件 manifest 并按需同步配置文件。 */
    public static function savePluginManifest(Plugin $plugin, array $manifest, bool $syncFile = true): Plugin
    {
        $manifest = self::completeManifest($manifest);
        $plugin->save(self::manifestDbData($manifest, $plugin));
        if ($syncFile && (int)($plugin->is_dev ?? 0) === 1) {
            FileService::syncManifestFile($manifest);
        }
        return PluginManager::getPlugin($manifest['name']);
    }

    /** 检查插件安装或启用条件。 */
    public static function checkRequirements(array $manifest, bool $requireEnabled = false): void
    {
        self::checkAppCompatibility($manifest);
        self::checkDependencies($manifest, $requireEnabled);
    }

    /** 检查插件与主应用版本是否兼容。 */
    public static function checkAppCompatibility(array $manifest): void
    {
        $info = self::getCompatibilityInfo($manifest);
        if (!$info['compatible']) {
            throw new ApiException($info['message']);
        }
    }

    /** 检查依赖插件是否满足要求。 */
    public static function checkDependencies(array $manifest, bool $requireEnabled = false): void
    {
        foreach (self::getDependencyInfo($manifest, $requireEnabled) as $dependency) {
            if (!$dependency['satisfied']) {
                throw new ApiException($dependency['message']);
            }
        }
    }

    /** 获取插件兼容性信息。 */
    public static function getCompatibilityInfo(array $manifest): array
    {
        $currentVersion = self::getAppVersion();
        $requiredVersion = trim((string)($manifest['support_version'] ?? ''));
        $compatible = $requiredVersion === '' || \versionGte($currentVersion, $requiredVersion);

        return [
            'current_version' => $currentVersion,
            'required_version' => $requiredVersion,
            'compatible' => $compatible,
            'message' => $compatible
                ? ($requiredVersion === '' ? '未声明最低基座版本，默认兼容' : '兼容当前系统版本')
                : '插件不兼容当前系统版本，要求主应用版本 >= ' . $requiredVersion . '，当前版本 ' . $currentVersion,
        ];
    }

    /** 获取依赖插件状态信息。 */
    public static function getDependencyInfo(array $manifest, bool $requireEnabled = true): array
    {
        $list = [];
        foreach (($manifest['dependencies'] ?? []) as $dependency) {
            $dependency = self::normalizeDependency($dependency);
            $name = $dependency['name'];
            if ($name === '') {
                continue;
            }

            $plugin = Plugin::where('name', $name)->find();
            $installed = $plugin && in_array((int)$plugin->status, [0, 1], true);
            $enabled = $plugin && (int)$plugin->status === 1;
            $versionMatched = !$plugin || $dependency['version'] === ''
                ? true
                : \versionGte((string)$plugin->version, $dependency['version']);
            $statusMatched = $requireEnabled ? $enabled : $installed;
            $satisfied = (bool)($plugin && $statusMatched && $versionMatched);

            $message = '已满足';
            if (!$plugin || !$installed) {
                $message = '缺少依赖插件：' . ($dependency['title'] ?: $name);
            } elseif ($requireEnabled && !$enabled) {
                $message = '依赖插件未启用：' . ($dependency['title'] ?: $name);
            } elseif (!$versionMatched) {
                $message = '依赖插件版本不满足：' . ($dependency['title'] ?: $name)
                    . ' 要求 >= ' . $dependency['version'] . '，当前版本 ' . $plugin->version;
            }

            $list[] = [
                'name' => $name,
                'title' => $dependency['title'] ?: ($plugin->title ?? $name),
                'version' => $dependency['version'],
                'current_version' => $plugin->version ?? '',
                'installed' => (bool)$installed,
                'enabled' => (bool)$enabled,
                'satisfied' => $satisfied,
                'message' => $message,
            ];
        }

        return $list;
    }

    /** 补全插件展示所需的派生信息。 */
    public static function enrichPlugin(Plugin|array $plugin): array
    {
        $data = $plugin instanceof Plugin ? $plugin->toArray() : $plugin;
        $manifest = self::completeManifest(\normalizeArray($data['manifest'] ?? []) ?: $data);
        $data['icon'] = \withAssetDomain($data['icon']);
        // 可见入口（管理入口优先，无管理入口回退应用入口）：空串=纯插件，前端据此隐藏入口与“打开”
        // 入口声明只存于 manifest JSON（plugin_app 表无 entry/manage_entry 独立列）
        $visibleEntry = self::visibleEntryFromManifest($manifest);

        // composer 状态快照存于独立列（json 可能解为对象/stdClass），统一归一为关联数组透出
        $composerState = \normalizeArray($data['composer_state'] ?? []) ?: [];

        return array_merge($data, [
            'manifest' => $manifest,
            'view_root' => $manifest['view_root'],
            'frontend_routes' => $manifest['frontend_routes'],
            'dependencies' => $manifest['dependencies'],
            'dependency_info' => self::getDependencyInfo($manifest),
            'app_version' => self::getAppVersion(),
            'compatibility_info' => self::getCompatibilityInfo($manifest),
            'has_entry' => $visibleEntry !== '',
            'visible_entry' => $visibleEntry,
            'entry_path' => PluginManager::entryMenuPath((string)($data['name'] ?? '')),
            'has_composer_deps' => !empty($manifest['composer']['require']),
            'composer_requires' => $manifest['composer']['require'] ?? [],
            'composer_state' => $composerState,
        ]);
    }

    /** 获取主应用版本号（唯一真源：基座根目录 manifest.json 的 version）。 */
    public static function getAppVersion(): string
    {
        return self::appManifestValue('version');
    }

    /**
     * 获取主应用版本号，读不到即失败。升级检查、选包与安装判定一律用它：
     * 空版本号会被 version_compare 静默判成「不兼容」，比直接报错更难排查。
     */
    public static function requireAppVersion(): string
    {
        $version = self::getAppVersion();
        if ($version === '') {
            throw new ApiException('manifest.json 不存在或缺少 version 字段，无法确定当前基座版本');
        }
        return $version;
    }

    /** 读取基座 manifest.json 的字段；清单缺失或解析失败回退默认值，不抛异常。 */
    public static function appManifestValue(string $key, string $default = ''): string
    {
        try {
            $manifest = BackupService::readAppManifest();
        } catch (\Throwable) {
            return $default;
        }
        return trim((string)($manifest[$key] ?? $default));
    }

    /** 解析插件是否为自定义插件。 */
    public static function resolveIsCustom(array $manifest, ?Plugin $plugin = null): int
    {
        return (int)($manifest['is_custom'] ?? ($plugin && !$plugin->isEmpty() ? $plugin->is_custom : 0));
    }

    /** 解析插件是否为开发模式。 */
    public static function resolveIsDev(array $manifest, ?Plugin $plugin = null): int
    {
        return (int)($manifest['is_dev'] ?? ($plugin && !$plugin->isEmpty() ? ($plugin->is_dev ?? 0) : 0));
    }

    /** 解析插件打开方式。 */
    public static function resolveOpenMode(array $manifest, ?Plugin $plugin = null): string
    {
        if (array_key_exists('open_mode', $manifest)) {
            return self::normalizeOpenMode((string)$manifest['open_mode']);
        }
        if ($plugin && !$plugin->isEmpty()) {
            return self::normalizeOpenMode((string)$plugin->open_mode);
        }
        return 'micro';
    }

    /** 规范化插件打开方式。 */
    public static function normalizeOpenMode(string $open_mode): string
    {
        return in_array($open_mode, ['micro', 'iframe'], true) ? $open_mode : 'micro';
    }

    /** 判断扫描时是否跳过插件。 */
    public static function shouldSkipScan(array $manifest, ?Plugin $plugin = null): bool
    {
        return self::resolveIsCustom($manifest, null) === 1
            || self::resolveIsDev($manifest, null) === 1
            || ($plugin && !$plugin->isEmpty() && ((int)$plugin->is_custom === 1 || (int)($plugin->is_dev ?? 0) === 1));
    }

    /** 补齐插件配置默认值。 */
    public static function completeManifest(array $manifest): array
    {
        self::validateManifest($manifest);
        $manifest['path'] = $manifest['path'] ?? 'plugin/' . $manifest['name'];
        $manifest['type'] = (int)($manifest['type'] ?? 1);
        // 入口字段只在“键不存在”时补默认值；显式空串代表纯插件（无页面入口），不得被默认值覆盖
        $manifest['entry'] = array_key_exists('entry', $manifest) ? (string)$manifest['entry'] : '/app/' . $manifest['name'];
        $manifest['manage_entry'] = array_key_exists('manage_entry', $manifest)
            ? (string)$manifest['manage_entry']
            : (string)$manifest['entry'];
        $manifest['view_root'] = $manifest['view_root'] ?? 'view';
        $manifest['icon'] = FileService::normalizeIcon((string)($manifest['icon'] ?? ''), $manifest['name']);
        $manifest['is_custom'] = self::resolveIsCustom($manifest);
        $manifest['is_core'] = (int)($manifest['is_core'] ?? 0);
        $manifest['source'] = (int)($manifest['source'] ?? 1);
        $manifest['scope'] = (int)($manifest['scope'] ?? 1);
        $manifest['platform'] = (int)($manifest['platform'] ?? 1);
        $manifest['is_dev'] = self::resolveIsDev($manifest);
        $manifest['open_mode'] = self::resolveOpenMode($manifest);
        $manifest['dependencies'] = $manifest['dependencies'] ?? [];
        $manifest['frontend_routes'] = $manifest['frontend_routes'] ?? [];
        $manifest['composer'] = self::normalizeComposer($manifest['composer'] ?? []);
        // hook 漏写时按约定补齐：callHook 在缺该键时静默跳过全部生命周期钩子，
        // 插件会只剩基座生成的入口菜单（afterInstall 里的子菜单/配置/权限全不落地）
        if (($manifest['hook'] ?? '') === '' && class_exists('plugin\\' . $manifest['name'] . '\\Plugin')) {
            $manifest['hook'] = 'plugin\\' . $manifest['name'] . '\\Plugin';
        }
        return $manifest;
    }

    /** 规范化 composer 依赖声明为 { require: {pkg: constraint} }。 */
    public static function normalizeComposer(mixed $composer): array
    {
        $require = [];
        $rawRequire = is_array($composer) ? ($composer['require'] ?? []) : [];
        if (is_array($rawRequire)) {
            foreach ($rawRequire as $pkg => $constraint) {
                $pkg = trim((string)$pkg);
                if ($pkg === '' || !is_string($pkg)) {
                    continue;
                }
                $require[$pkg] = is_string($constraint) ? trim($constraint) : (string)$constraint;
            }
        }
        return ['require' => $require];
    }

    /** 从 manifest 提取数据库保留字段，避免同步和展示处重复拼装。 */
    public static function manifestDbData(array $manifest, ?Plugin $plugin = null): array
    {
        $data = self::pickManifestDbFields($manifest);
        // manage_entry 忠实取 completeManifest 的结果（含空串），不再在此处回退到 entry
        $data['manage_entry'] = (string)($manifest['manage_entry'] ?? '');
        $data['open_mode'] = self::resolveOpenMode($manifest, $plugin);
        $data['is_custom'] = self::resolveIsCustom($manifest, $plugin);
        $data['is_dev'] = self::resolveIsDev($manifest, $plugin);
        $data['manifest'] = $manifest;

        return $data;
    }

    /** 构建自定义插件保存数据。 */
    public static function buildCustomPluginData(array $data, ?Plugin $plugin = null): array
    {
        $data['name'] = \normalizePluginName((string)($data['name'] ?? ''));
        if (!$plugin) {
            $data['status'] = $data['status'] ?? 0;
        }
        $data['is_custom'] = $plugin ? (int)$plugin->is_custom : 1;
        $data['is_dev'] = (int)($data['is_dev'] ?? 0);
        $data['open_mode'] = self::normalizeOpenMode((string)($data['open_mode'] ?? 'micro'));
        $data['icon'] = FileService::pluginPublicAssetUrl($data['name']);
        $data['path'] = $plugin ? (string)($plugin->path ?: 'plugin/' . $data['name']) : 'plugin/' . $data['name'];
        $data['entry'] = (string)($data['entry'] ?? '');
        $data['manage_entry'] = (string)($data['manage_entry'] ?? '');
        $data['type'] = (int)($data['type'] ?? 1);
        $data['is_core'] = (int)($data['is_core'] ?? 0);
        $data['source'] = (int)($data['source'] ?? 1);
        $data['scope'] = (int)($data['scope'] ?? 1);
        $data['platform'] = (int)($data['platform'] ?? 1);

        $manifest = array_merge($plugin ? (array)$plugin->manifest : [], $data['manifest'] ?? []);
        $manifest = array_merge($manifest, self::pickManifestDbFields($data));

        foreach (['dependencies', 'frontend_routes', 'remote_vue'] as $field) {
            if (array_key_exists($field, $data)) {
                $manifest[$field] = is_array($data[$field]) ? $data[$field] : [];
            }
        }

        $manifest = self::completeManifest($manifest);
        $dbData = self::manifestDbData($manifest, $plugin);
        $dbData['status'] = $data['status'] ?? ($plugin ? (int)$plugin->status : 0);

        return $dbData;
    }

    /** 将插件配置同步到数据库。 */
    public static function syncPlugin(array $manifest, ?Plugin $plugin = null): Plugin
    {
        $manifest = self::completeManifest($manifest);
        $plugin = $plugin ?: Plugin::where('name', $manifest['name'])->findOrEmpty();
        $data = self::manifestDbData($manifest, $plugin);

        if ($plugin->isEmpty()) {
            $data['status'] = 2;
        }

        $plugin->save($data);
        return $plugin;
    }

    /** 读取并校验插件配置文件。 */
    public static function readManifest(string $path): array
    {
        $manifest = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($manifest)) {
            throw new ApiException('插件配置文件格式错误：' . $path);
        }
        self::validateManifest($manifest);
        return $manifest;
    }

    /** 标识格式是否合法（单一来源，与 validateManifest 一致）。 */
    public static function isValidName(string $name): bool
    {
        return (bool)preg_match(self::NAME_PATTERN, $name);
    }

    /** 是否为基座兜底保留标识（市场不可达时的兜底拦截，完整清单见应用中心插件）。 */
    public static function isReservedName(string $name): bool
    {
        return in_array($name, self::RESERVED_NAMES, true);
    }

    /**
     * 校验标识格式规则：格式（前缀/字符集） + 自定义命名最小长度。
     * 与保留名/市场无关，为基座与插件共用的格式契约。
     * @return string 错误信息；合法返回空串
     */
    public static function checkNameFormat(string $name): string
    {
        if (!self::isValidName($name)) {
            return '应用标识不合法：必须以 am_ 开头，且只能包含小写字母、数字和下划线，首字符须为字母';
        }
        if (strlen($name) - strlen('am_') < self::MIN_NAME_SUFFIX_LEN) {
            return '应用标识过短：am_ 之后的自定义命名至少需 ' . self::MIN_NAME_SUFFIX_LEN . ' 个字符';
        }
        return '';
    }

    /**
     * 基座本地兜底校验：格式 + 最小长度 + 基座兜底保留名。
     * 应用中心插件不可达时仍能拦截核心保留名；完整权威判定由市场接口 checkName 返回。
     * @return string 错误信息；合法返回空串
     */
    public static function checkNameRule(string $name): string
    {
        if ($err = self::checkNameFormat($name)) {
            return $err;
        }
        if (self::isReservedName($name)) {
            return '该标识为系统保留名，不可使用';
        }
        return '';
    }

    /** 校验插件配置必填字段。 */
    public static function validateManifest(array $manifest): void
    {
        foreach (['name', 'title', 'version'] as $field) {
            if (empty($manifest[$field])) {
                throw new ApiException('插件配置缺少字段：' . $field);
            }
        }
        if (!self::isValidName((string)$manifest['name'])) {
            throw new ApiException('插件标识必须以 am_ 开头，并且只能包含小写字母、数字和下划线');
        }
    }

    /** 获取插件保存的配置快照。 */
    public static function getManifest(Plugin $plugin): array
    {
        return self::completeManifest(self::mergePluginRecordToManifest($plugin));
    }

    /**
     * 从 manifest 解析“基座打开时使用的可见入口”（管理入口优先，无则回退应用入口）。
     *
     * entry / manage_entry 只存于 manifest JSON（plugin_app 表无同名列），故入口判定一律以此为准。
     * 显式 is_backstage=false 视为无后台可见入口；空串代表纯插件。
     */
    public static function visibleEntryFromManifest(array $manifest): string
    {
        if (isset($manifest['is_backstage']) && !(bool)$manifest['is_backstage']) {
            return '';
        }
        return trim((string)($manifest['manage_entry'] ?? '')) ?: trim((string)($manifest['entry'] ?? ''));
    }

    /**
     * 读取插件目录内 manifest.json 的原始入口声明（不做记录合并）。
     *
     * 入口以数据库 manifest 列为首要来源；个别 dev 插件的数据库清单与磁盘 manifest.json 不同步时，
     * 以磁盘清单作为兜底。读不到或解析失败则返回空数组。
     */
    public static function fileManifest(Plugin $plugin): array
    {
        $dir = FileService::pluginRootFromPath((string)($plugin->path ?? ''), (string)$plugin->name);
        $file = $dir . '/' . FileService::MANIFEST_FILE;
        if (!is_file($file)) {
            return [];
        }
        $manifest = json_decode((string)@file_get_contents($file), true);
        return is_array($manifest) ? $manifest : [];
    }

    /** 获取插件 manifest 快照。 */
    public static function pluginManifestSnapshot(Plugin $plugin): array
    {
        return self::mergePluginRecordToManifest($plugin);
    }

    /** 规范化依赖声明。 */
    public static function normalizeDependency(mixed $dependency): array
    {
        if (is_array($dependency)) {
            return [
                'name' => trim((string)($dependency['name'] ?? '')),
                'title' => trim((string)($dependency['title'] ?? '')),
                'version' => trim((string)($dependency['version'] ?? '')),
            ];
        }

        return [
            'name' => trim((string)$dependency),
            'title' => '',
            'version' => '',
        ];
    }

    /** 规范化版本号。 */
    public static function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV') ?: '0.0.0';
    }

    /** 比较版本号。 */
    public static function comparePluginVersion(string $left, string $right): int
    {
        return version_compare(self::normalizeVersion($left), self::normalizeVersion($right));
    }

    /** 提取 manifest 与插件表共享字段。 */
    protected static function pickManifestDbFields(array $data): array
    {
        $defaults = [
            'type' => 1,
            'version' => '1.0.0',
            'open_mode' => 'micro',
            'is_custom' => 0,
            'is_core' => 0,
            'source' => 1,
            'scope' => 1,
            'platform' => 1,
            'is_dev' => 0,
        ];
        $fields = [];
        foreach (self::MANIFEST_DB_FIELDS as $field) {
            $fields[$field] = $data[$field] ?? ($defaults[$field] ?? '');
        }
        foreach (self::INT_MANIFEST_DB_FIELDS as $field) {
            $fields[$field] = (int)$fields[$field];
        }
        return $fields;
    }

    /** 将插件表字段覆盖到 manifest 快照。 */
    protected static function mergePluginRecordToManifest(Plugin $plugin): array
    {
        $manifest = \normalizeArray($plugin->manifest) ?: [];
        $record = $plugin->toArray();
        $data = self::pickManifestDbFields($record);
        // 仅用"表记录中实际存在的字段"覆盖 manifest（含空串，忠实反映 DB 清空操作）；
        // pickManifestDbFields 对缺失字段补的默认空值不参与覆盖，避免语义丢失
        $overwrite = [];
        foreach ($data as $field => $value) {
            if (array_key_exists($field, $record)) {
                $overwrite[$field] = $value;
            }
        }
        return array_merge($manifest, $overwrite);
    }
}

