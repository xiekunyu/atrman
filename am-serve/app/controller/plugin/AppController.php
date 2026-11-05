<?php

namespace app\controller\plugin;

use app\basic\BaseController;
use app\exception\ApiException;
use app\model\plugin\App as Plugin;
use app\model\system\File;
use app\model\system\User;
use app\utils\Directory;
use app\service\FileService;
use app\service\Permission;
use app\service\PluginManager;
use app\service\PluginManifestService;
use app\service\PluginComposerService;
use app\service\StoreService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Log;
use support\Request;
use support\Response;
use Workerman\Timer;

class AppController extends BaseController
{
    /** 初始化插件控制器依赖。 */
    public function initialize(): void
    {
        $this->model = new Plugin();
    }
#[Get('/plugin/app/index')]
    #[Permission(title: '插件列表', slug: 'system:plugin:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['keywords', ''],
            ['status', ''],
            ['name', ''],
            ['type', ''],
            ['is_custom', ''],
            ['is_core', ''],
            ['source', ''],
            ['scope', ''],
            ['platform', ''],
            ['is_dev', ''],
        ]);
        // 入口声明存于 manifest JSON（非独立列），故“是否只看有可见入口的应用”在 PHP 层过滤：
        // 1=只取有入口（首页应用中心），0=只取纯插件，不传=全部（应用管理页）。
        $visibleEntry = $request->input('visible_entry', '');
        $model = $this->search($this->model, $where);
        $orderField = $request->input('orderField', '');
        $orderType = $request->input('orderType', 'desc');
        if (empty($orderField)) {
            $orderField = $this->model->getPk();
        }

        $list = $model->order($orderField, $orderType)->select()->toArray();
        $visibleList = [];
        $context = User::getAuthContext();
        foreach ($list as $plugin) {
            $auth = $this->normalizePluginAuth($plugin['auth'] ?? []);
            if (User::hasPermission($auth, $context)) {
                $auth['displayData'] = User::getStructure($auth);
                $plugin['auth'] = $auth;
                $visibleList[] = $plugin;
            }
        }
        if ($visibleEntry !== '' && $visibleEntry !== null) {
            $wantVisible = (int)$visibleEntry === 1;
            $visibleList = array_values(array_filter(
                $visibleList,
                fn($row) => (PluginManifestService::visibleEntryFromManifest(
                    \normalizeArray($row['manifest'] ?? [])
                ) !== '') === $wantVisible
            ));
        }
        return $this->returnPluginListData($visibleList);
    }

    /**
     * 获取可归属菜单的插件选项（开发应用或自建应用）。
     *
     * 供后台菜单表单“插件标识”下拉（远程数据源）使用：仅列出开发模式(is_dev=1)或自建(is_custom=1)
     * 且已安装（启用/禁用）的插件；未加 Permission 注解，任何登录用户均可读取，
     * 返回 [{label: 插件名称, value: 插件标识}]，空标识代表系统基座菜单。
     */
    #[Get('/plugin/app/menuOptions')]
    public function menuOptions(Request $request): Response
    {
        $list = $this->model
            ->where('status', 'in', [0, 1])
            ->where(function ($query) {
                $query->where('is_dev', 1)->whereOr('is_custom', 1);
            })
            ->field('id, name, title')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $data = array_map(fn($p) => [
            'label' => (string)($p['title'] !== '' ? $p['title'] : $p['name']),
            'value' => (string)$p['name'],
        ], $list);
        return success('', $data);
    }
#[Get('/plugin/app/read')]
    #[Permission(title: '插件详情', slug: 'system:plugin:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('插件不存在');
        }
        $auth = $this->normalizePluginAuth($info['auth'] ?? []);
        $context = User::getAuthContext();
        if (!User::hasPermission($auth, $context)) {
            return warning('无权查看插件');
        }

        // auth 只存 id 列表，前端可见范围回显依赖显示对象：与 index() 一致从库内 id 重建后补入，
        // 否则详情页被 read() 刷新后表单被清空（看似“设置不生效/下次进来没了”）
        $auth['displayData'] = User::getStructure($auth);
        $data = PluginManifestService::enrichPlugin($info);
        $data['auth'] = $auth;
        return success('', $data);
    }

    /**
     * 按插件标识获取“打开插件”所需的运行时数据。
     *
     * 基座宿主路由 /plugin-app/{name} 经此接口取插件信息：标识在实例内唯一且不随备份迁移变化，
     * 而记录 id 会因目标库自增序列不同而变化，故入口统一按标识解析。
     * 与 menuOptions 一样不加 Permission 注解（任何登录用户可读），
     * 可见范围沿用插件 auth 判定，未启用与无入口的插件在此统一拦住。
     */
    #[Get('/plugin/app/readByName')]
    public function readByName(Request $request): Response
    {
        $name = \normalizePluginName((string)$request->get('name', ''));
        if ($name === '') {
            return warning('参数错误');
        }

        $info = $this->model->where('name', $name)->findOrEmpty();
        if ($info->isEmpty()) {
            return warning('插件不存在');
        }
        $auth = $this->normalizePluginAuth($info['auth'] ?? []);
        if (!User::hasPermission($auth, User::getAuthContext())) {
            return warning('无权查看插件');
        }
        if ((int)$info->status !== 1) {
            return warning('插件未启用，无法打开');
        }
        if (!PluginManager::hasVisibleEntry($info)) {
            return warning('插件未配置应用入口');
        }

        return success('', PluginManifestService::enrichPlugin($info));
    }
#[Post('/plugin/app/auth')]
    #[Permission(title: '保存插件可见范围', slug: 'system:plugin:auth')]
    public function auth(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('插件不存在');
        }

        $auth = $this->normalizePluginAuth($request->post('auth', []));
        if (!$info->save(['auth' => $auth])) {
            return warning('保存失败');
        }

        $info = $this->model->findOrEmpty($id);
        // 同 read()：返回前重建显示对象，保证前端保存后能直接回显不丢选择
        $savedAuth = $this->normalizePluginAuth($info['auth'] ?? []);
        $savedAuth['displayData'] = User::getStructure($savedAuth);
        $data = PluginManifestService::enrichPlugin($info);
        $data['auth'] = $savedAuth;
        return success('保存成功', $data);
    }
#[Post('/plugin/app/dependency/save')]
    #[Permission(title: '新增插件依赖', slug: 'system:plugin:dependency:save')]
    public function dependencySave(Request $request): Response
    {
        $data = $request->post();
        $plugin = Plugin::requireDevByAppIdOrId($data['app_name'] ?? '', $data['plugin_id'] ?? ($data['id'] ?? ''), '仅开发模式插件允许管理依赖');
        $dependency = $this->buildDependencyFromRequest($data);
        if ($dependency['name'] === (string)$plugin->name) {
            return warning('不能依赖当前插件自身');
        }

        $manifest = $this->getPluginManifestData($plugin);
        $dependencies = $this->normalizeDependencyList($manifest['dependencies'] ?? []);
        foreach ($dependencies as $item) {
            if ($item['name'] === $dependency['name']) {
                return warning('依赖已存在');
            }
        }
        $dependencies[] = $dependency;
        $plugin = $this->saveDependencyList($plugin, $manifest, $dependencies);
        return success('保存成功', PluginManifestService::enrichPlugin($plugin));
    }
#[Post('/plugin/app/dependency/update')]
    #[Permission(title: '更新插件依赖', slug: 'system:plugin:dependency:update')]
    public function dependencyUpdate(Request $request): Response
    {
        $data = $request->post();
        $plugin = Plugin::requireDevByAppIdOrId($data['app_name'] ?? '', $data['plugin_id'] ?? ($data['id'] ?? ''), '仅开发模式插件允许管理依赖');
        $originalName = \normalizePluginName((string)($data['original_name'] ?? ''));
        $dependency = $this->buildDependencyFromRequest($data);
        if ($dependency['name'] === (string)$plugin->name) {
            return warning('不能依赖当前插件自身');
        }

        $manifest = $this->getPluginManifestData($plugin);
        $dependencies = $this->normalizeDependencyList($manifest['dependencies'] ?? []);
        $found = false;
        foreach ($dependencies as $index => $item) {
            if ($item['name'] === $dependency['name'] && $item['name'] !== $originalName) {
                return warning('依赖已存在');
            }
            if ($item['name'] === $originalName) {
                $dependencies[$index] = $dependency;
                $found = true;
            }
        }
        if (!$found) {
            return warning('依赖不存在');
        }

        $plugin = $this->saveDependencyList($plugin, $manifest, $dependencies);
        return success('保存成功', PluginManifestService::enrichPlugin($plugin));
    }
#[Post('/plugin/app/dependency/delete')]
    #[Permission(title: '删除插件依赖', slug: 'system:plugin:dependency:delete')]
    public function dependencyDelete(Request $request): Response
    {
        $data = $request->post();
        $plugin = Plugin::requireDevByAppIdOrId($data['app_name'] ?? '', $data['plugin_id'] ?? ($data['id'] ?? ''), '仅开发模式插件允许管理依赖');
        $name = \normalizePluginName((string)($data['dependency_name'] ?? ''));
        $manifest = $this->getPluginManifestData($plugin);
        $rawDependencies = \normalizeArray($manifest['dependencies'] ?? []);
        $dependencies = array_values(array_filter(
            $this->normalizeDependencyList($rawDependencies),
            fn($item) => $item['name'] !== $name
        ));
        if (count($dependencies) === count($rawDependencies)) {
            return warning('依赖不存在');
        }

        $plugin = $this->saveDependencyList($plugin, $manifest, $dependencies);
        return success('删除成功', PluginManifestService::enrichPlugin($plugin));
    }

    /** 获取插件 manifest 数据。 */
    protected function getPluginManifestData(Plugin $plugin): array
    {
        return \normalizeArray($plugin->manifest) ?: $plugin->toArray();
    }

    /** 规范化插件依赖列表。 */
    protected function normalizeDependencyList(mixed $dependencies): array
    {
        return array_values(array_filter(array_map(
            fn($item) => PluginManifestService::normalizeDependency($item),
            \normalizeArray($dependencies)
        ), fn($item) => $item['name'] !== ''));
    }

    /** 从请求数据构建插件依赖。 */
    protected function buildDependencyFromRequest(array $data): array
    {
        return PluginManifestService::normalizeDependency([
            'name' => \normalizePluginName((string)($data['dependency_name'] ?? '')),
            'title' => (string)($data['dependency_title'] ?? ''),
            'type' => (string)($data['dependency_type'] ?? 'plugin'),
            'version' => (string)($data['dependency_version'] ?? ''),
        ]);
    }

    /** 保存插件依赖到 manifest。 */
    protected function saveDependencyList(Plugin $plugin, array $manifest, array $dependencies): Plugin
    {
        $manifest['dependencies'] = array_values($dependencies);
        return PluginManifestService::savePluginManifest($plugin, $manifest);
    }
#[Get('/plugin/app/checkName')]
    #[Permission(title: '检测应用标识', slug: 'system:plugin:save')]
    public function checkName(Request $request): Response
    {
        $name = (string)$request->get('name', '');
        if (trim($name) === '') {
            return warning('请输入应用标识');
        }
        return success('', StoreService::checkIdentifier($name));
    }
#[Post('/plugin/app/save')]
    #[Permission(title: '创建插件', slug: 'system:plugin:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $data['name'] = \normalizePluginName((string)($data['name'] ?? ''));
        // 提交时同样强制校验：格式合法 + 不与市场冲突（不合法不允许创建）
        $check = StoreService::checkIdentifier($data['name']);
        if (!$check['valid']) {
            return warning($check['message']);
        }
        $data['name'] = $check['name'];
        $fileId = $data['icon'][0] ?? '';
        $iconFile = null;
        if (empty($fileId) || (string)$fileId === '0') {
            $data['icon'] = '';
        } else {
            $file = File::find($fileId);
            if ($file->isEmpty()) {
                return warning('图标不存在');
            }
            $iconFile = $file;
            $data['icon'] = $file->url.$file->path;
        }

        $pluginData = $this->buildCustomPluginData($data);
        try {
            FileService::scaffoldCustomPlugin($pluginData['manifest']);
            // 保存图标：本地文件库直读磁盘复制，避免绕 HTTP 下载受内网检查/域名解析影响
            if ($iconFile) {
                FileService::saveCustomPluginLogoFromFile($iconFile, $pluginData['name']);
            }
            if (!$this->model->save($pluginData)) {
                FileService::removeCustomPluginFiles($pluginData['name']);
                return warning('创建插件失败');
            }
            // 创建后立即生成“管理入口”顶级菜单（与 install/reload 行为对齐，幂等）
            PluginManager::syncEntry($this->model);
        } catch (\Throwable $e) {
            FileService::removeCustomPluginFiles($pluginData['name']);
            throw $e;
        }

        $result = $this->model->toArray();
        $result['scaffold_path'] = $pluginData['path'];
        return success('创建插件成功', $result);
    }
#[Post('/plugin/app/update')]
    #[Permission(title: '更新插件', slug: 'system:plugin:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('插件不存在');
        }
        if ((int)$info->is_custom !== 1) {
            return warning('基础应用不允许编辑信息');
        }

        $data = $request->post();
        $data['name'] = \normalizePluginName((string)($data['name'] ?? $info->name));
        if ($data['name'] !== (string)$info->name) {
            return warning('插件标识不可修改');
        }
        $fileId = $data['icon'][0] ?? '';
        $iconFile = null;
        if (empty($fileId)) {
            $data['icon'] = '';
        } else {
            if (is_numeric($fileId)) {
                $file = File::find($fileId);
                if ($file->isEmpty()) {
                    return warning('图标不存在');
                }
                $iconFile = $file;
                $data['icon'] = $file->url.$file->path;
            }else{
                // 回显的是既有图标地址（非新选文件 id），保持原图标不变
                unset($data['icon']);
            }
        }
        $pluginData = $this->buildCustomPluginData($data, $info);
        // 以入库数据（含入口声明）为准装配脚手架，避免已存在的 manifest.json 把空入口重新回填成默认地址
        FileService::ensureCustomPluginScaffold($pluginData['manifest']);
        // 保存图标：本地文件库直读磁盘复制，仅远程存储回退为 URL 下载
        if ($iconFile) {
            FileService::saveCustomPluginLogoFromFile($iconFile, $pluginData['name']);
        }
        if (!$info->save($pluginData)) {
            return warning('更新插件失败');
        }
        if ((int)$pluginData['is_dev'] === 1) {
            FileService::syncManifestFile($pluginData['manifest']);
        }
        // 名称/入口/图标变更后同步“管理入口”菜单（幂等）
        PluginManager::syncEntry($info);

        return success('更新插件成功', $info);
    }
#[Post('/plugin/app/scaffold')]
    #[Permission(title: '重新生成插件模板', slug: 'system:plugin:scaffold')]
    public function scaffold(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('插件不存在');
        }
        if ((int)$info->is_custom !== 1) {
            return warning('基础应用不允许生成开发模板');
        }

        $manifest = json_decode(json_encode($info->manifest ?: $info->toArray()), true) ?: [];
        FileService::regenerateCustomPluginScaffold($manifest);
        return success('操作成功', PluginManifestService::enrichPlugin($info));
    }
#[Post('/plugin/app/scan')]
    #[Permission(title: '扫描插件', slug: 'system:plugin:scan')]
    public function scan(): Response
    {
        $list = array_map(fn($plugin) => PluginManifestService::enrichPlugin($plugin), PluginManager::scan());
        return success('扫描插件成功', $list, count($list));
    }
#[Post('/plugin/app/install')]
    #[Permission(title: '安装插件', slug: 'system:plugin:install')]
    public function install(Request $request): Response
    {
        $plugin = PluginManager::install($this->getPluginName($request));
        $plugin = $this->refreshComposerSnapshot((string)$plugin->name) ?: $plugin;
        return success('安装插件成功', PluginManifestService::enrichPlugin($plugin));
    }
#[Post('/plugin/app/package')]
    #[Permission(title: '生成插件安装包', slug: 'system:plugin:package')]
    public function package(Request $request): Response
    {
        $plugin = $this->model->findOrEmpty($request->post('id'));
        if ($plugin->isEmpty()) {
            return warning('插件不存在');
        }
        if ((int)$plugin->is_custom !== 1) {
            return warning('基础应用不允许生成安装包');
        }

        $data = $request->post();
        $manifest = $this->mergePackageManifest($plugin, $data);
        $overrides = $this->packageOverrides($data);
        // 版本/Composer 依赖/Bridge 权限写回原 manifest（开发态与发布态保持一致）。
        // savePluginManifest 只在开发模式落盘，打包会直接读取磁盘清单，故此处无条件同步一次。
        PluginManifestService::savePluginManifest($plugin, $manifest, false);
        FileService::syncManifestFile($manifest);

        $package = FileService::buildPackage((string)$plugin->name, $overrides);
        return response()->download($package['path'], $package['name'])->withHeaders([
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** 合并打包表单中的应用配置，未提交的字段沿用原 manifest。 */
    protected function mergePackageManifest(Plugin $plugin, array $data): array
    {
        $manifest = PluginManifestService::getManifest($plugin);

        if (array_key_exists('version', $data)) {
            $version = trim((string)$data['version']);
            if ($version === '') {
                throw new ApiException('版本号不能为空');
            }
            $manifest['version'] = $version;
        }
        if (array_key_exists('composer', $data)) {
            $manifest['composer'] = PluginManifestService::normalizeComposer($data['composer']);
        }
        if (array_key_exists('bridge_scopes', $data)) {
            $bridge = \normalizeArray($manifest['bridge'] ?? []) ?: [];
            $bridge['trust'] = (string)($bridge['trust'] ?? 'same-origin');
            $bridge['scopes'] = $this->normalizeBridgeScopes($data['bridge_scopes']);
            $manifest['bridge'] = $bridge;
        }

        return $manifest;
    }

    /** 提取仅写入安装包、不影响开发态配置的发布字段。 */
    protected function packageOverrides(array $data): array
    {
        $overrides = [];
        foreach (['entry', 'manage_entry'] as $field) {
            if (array_key_exists($field, $data)) {
                $overrides[$field] = trim((string)$data[$field]);
            }
        }
        if (array_key_exists('is_core', $data)) {
            $overrides['is_core'] = (int)$data['is_core'] === 1 ? 1 : 0;
        }

        return $overrides;
    }

    /** 规范化 Bridge 权限作用域列表。 */
    protected function normalizeBridgeScopes(mixed $scopes): array
    {
        $list = [];
        foreach (is_array($scopes) ? $scopes : [] as $scope) {
            $scope = trim((string)$scope);
            if ($scope !== '' && !in_array($scope, $list, true)) {
                $list[] = $scope;
            }
        }

        return $list;
    }
#[Post('/plugin/app/backup')]
    #[Permission(title: '备份插件', slug: 'system:plugin:backup')]
    public function backup(Request $request): Response
    {
        PluginManager::backup($this->getPluginName($request));
        return success('备份生成成功');
    }
#[Post('/plugin/app/installPackage')]
    #[Permission(title: '安装插件包', slug: 'system:plugin:installPackage')]
    public function installPackage(Request $request): Response
    {
        $overwrite = (bool)$request->post('overwrite', false);
        $file = $request->file('file');
        if ($file) {
            if (!$file->isValid()) {
                return warning('请选择有效的插件安装包');
            }
            if (strtolower($file->getUploadExtension()) !== 'zip') {
                return warning('仅支持 zip 插件安装包');
            }
            $zipPath = $file->getPathname();
        } else {
            $fileId = (int)$request->post('file_id');
            if ($fileId <= 0) {
                return warning('请选择插件安装包');
            }
            $zipPath = $this->getPackageFilePath($fileId);
        }

        // 与应用市场升级同一编排：启用中自动停用 → 覆盖安装 → 逆序自动恢复，失败全量回滚
        $result = PluginManager::installPackageAutoDisable($zipPath, $overwrite);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if (empty($result['success'])) {
            // 回滚有边界（数据快照缺失、插件需人工重启用）时如实并入文案，不替用户略过
            $notes = [];
            if (!empty($data['db_note'])) {
                $notes[] = (string)$data['db_note'];
            }
            if (!empty($data['enable_failed'])) {
                $notes[] = '以下插件需人工重新启用：' . implode('、', (array)$data['enable_failed']);
            }
            return warning($result['message'] . ($notes === [] ? '' : '；' . implode('；', $notes)), $data);
        }

        $name = (string)($result['plugin_name'] ?? '');
        $plugin = $this->refreshComposerSnapshot($name) ?: PluginManager::getPlugin($name);
        return success((string)$result['message'], array_merge($data, ['plugin' => PluginManifestService::enrichPlugin($plugin)]));
    }
#[Post('/plugin/app/restorePackage')]
    #[Permission(title: '恢复插件备份', slug: 'system:plugin:installPackage')]
    public function restorePackage(Request $request): Response
    {
        $file = $request->file('file');
        if ($file) {
            if (!$file->isValid()) {
                return warning('请选择有效的插件备份包');
            }
            if (strtolower($file->getUploadExtension()) !== 'zip') {
                return warning('仅支持 zip 插件备份包');
            }
            $result = PluginManager::restorePackage($file->getPathname());
            $this->refreshComposerSnapshot((string)($result['plugin'] ?? ''));
            return success('恢复备份成功', $result);
        }

        $fileId = (int)$request->post('file_id');
        if ($fileId <= 0) {
            return warning('请选择插件备份包');
        }

        $zipPath = $this->getPackageFilePath($fileId);
        $result = PluginManager::restorePackage($zipPath);
        $this->refreshComposerSnapshot((string)($result['plugin'] ?? ''));
        return success('恢复备份成功', $result);
    }

    /**
     * 安装/覆盖安装/恢复后自动跑一次离线依赖检测，刷新 composer_state 快照。
     *
     * check() 纯离线不跑 composer；换插件目录不动基座 vendor，进程内 InstalledVersions 仍准。
     * 失败不阻断主流程：用户打开依赖弹窗时会重算。返回刷新后的记录供 enrich 透出最新状态。
     */
    protected function refreshComposerSnapshot(string $name): ?Plugin
    {
        if ($name === '') {
            return null;
        }
        try {
            PluginComposerService::check($name);
        } catch (\Throwable $e) {
            return null;
        }
        $fresh = $this->model->where('name', $name)->findOrEmpty();
        return $fresh->isEmpty() ? null : $fresh;
    }
#[Post('/plugin/app/packageInspect')]
    #[Permission(title: '预检插件包', slug: 'system:plugin:installPackage')]
    public function packageInspect(Request $request): Response
    {
        $fileId = (int)$request->post('file_id');
        if ($fileId <= 0) {
            return warning('请选择插件安装包');
        }

        $zipPath = $this->getPackageFilePath($fileId);
        return success('预检插件包成功', PluginManager::inspectPackage($zipPath));
    }
#[Post('/plugin/app/uninstall')]
    #[Permission(title: '卸载插件', slug: 'system:plugin:uninstall')]
    public function uninstall(Request $request): Response
    {
        $name = $this->getPluginName($request);
        $plugin = Plugin::where('name', $name)->findOrEmpty();
        if ($plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        if (in_array((int)$plugin->status, [0, 2], true)) {
            $confirmName = \normalizePluginName((string)$request->post('confirm_name', ''));
            if ($confirmName !== $name) {
                return warning('请输入正确的插件标识后再卸载');
            }
        }

        PluginManager::uninstall($name);
        return success('卸载成功，备份已生成');
    }
#[Post('/plugin/app/enable')]
    #[Permission(title: '启用插件', slug: 'system:plugin:enable')]
    public function enable(Request $request): Response
    {
        $plugin = PluginManager::enable($this->getPluginName($request));
        return $this->successWithGracefulReload('启用插件成功', $plugin);
    }
#[Post('/plugin/app/disable')]
    #[Permission(title: '禁用插件', slug: 'system:plugin:disable')]
    public function disable(Request $request): Response
    {
        $plugin = PluginManager::disable($this->getPluginName($request));
        return $this->successWithGracefulReload('禁用插件成功', $plugin);
    }

    /**
     * 启停成功响应并自动触发 worker 平滑重启：插件注册表/路由为跨进程内存态，
     * 仅清缓存时其他 worker 要等下次重启才收敛，平滑重启使其立即生效。
     *
     * 延后 1 秒发信号：避免当前进程在响应 flush 前被重启导致前端收到失败；
     * Windows 等不支持 SIGUSR1 的环境仅记日志提示，不影响启停结果本身。
     */
    protected function successWithGracefulReload(string $message, Plugin $plugin): Response
    {
        Timer::delay(1, function () {
            $reload = PluginComposerService::gracefulReload();
            if (!$reload['ok']) {
                Log::info('插件启停后自动平滑重启未执行：' . $reload['message']);
            }
        });
        return success($message . '，worker 正在后台平滑重启以跨进程生效', PluginManifestService::enrichPlugin($plugin));
    }
#[Post('/plugin/app/reload')]
    #[Permission(title: '重载插件', slug: 'system:plugin:reload')]
    public function reload(Request $request): Response
    {
        $plugin = PluginManager::reload($this->getPluginName($request));
        return success('重载插件成功', PluginManifestService::enrichPlugin($plugin));
    }
#[Post('/plugin/app/composer/check')]
    #[Permission(title: '检测插件依赖', slug: 'system:plugin:composer')]
    public function composerCheck(Request $request): Response
    {
        $state = PluginComposerService::check($this->getPluginName($request));
        return success('依赖检测完成', $state);
    }
#[Post('/plugin/app/composer/install')]
    #[Permission(title: '安装插件依赖', slug: 'system:plugin:composer')]
    public function composerInstall(Request $request): Response
    {
        $name = $this->getPluginName($request);
        $targets = (array)$request->post('targets', []);
        // targets 缺省时以最新检测出的 missing + incompatible 为安装子集
        if (!$targets) {
            $state = PluginComposerService::check($name);
            foreach (($state['missing'] ?? []) as $pkg => $constraint) {
                $targets[$pkg] = (string)$constraint;
            }
            foreach (($state['incompatible'] ?? []) as $pkg => $info) {
                $targets[$pkg] = (string)($info['want'] ?? '');
            }
        }
        if (!$targets) {
            return warning('没有需要安装的依赖（均已满足）');
        }
        $job = PluginComposerService::dispatchInstall($name, $targets, (int)($this->userInfo['id'] ?? 0));
        return success('安装任务已提交', $job);
    }
#[Get('/plugin/app/composer/status')]
    #[Permission(title: '查询依赖安装进度', slug: 'system:plugin:composer')]
    public function composerStatus(Request $request): Response
    {
        $jobId = (string)$request->get('job_id', '');
        if ($jobId === '') {
            return warning('缺少任务标识');
        }
        $snap = PluginComposerService::status($jobId);
        if ($snap === null) {
            return warning('任务不存在或已过期');
        }
        return success('', $snap);
    }
#[Post('/plugin/app/composer/reload')]
    #[Permission(title: '重载以生效依赖', slug: 'system:plugin:composer')]
    public function composerReload(): Response
    {
        return success('', PluginComposerService::gracefulReload());
    }


    /** 获取文件库中插件包的本地路径。 */
    protected function getPackageFilePath(int $fileId): string
    {
        $file = File::findOrEmpty($fileId);
        if ($file->isEmpty()) {
            throw new ApiException('插件安装包不存在');
        }

        $extension = strtolower((string)($file->ext ?: pathinfo((string)$file->name, PATHINFO_EXTENSION)));
        if ($extension !== 'zip') {
            throw new ApiException('仅支持 zip 插件安装包');
        }

        $relativePath = str_replace('\\', '/', ltrim((string)$file->path, '/'));
        if ($relativePath === '' || str_contains($relativePath, "\0") || $relativePath[0] === '/' || preg_match('/^[A-Za-z]:/', $relativePath)) {
            throw new ApiException('插件安装包路径不正确');
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '..') {
                throw new ApiException('插件安装包路径不正确');
            }
        }

        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $roots = [public_path(), base_path()];
        foreach ($roots as $root) {
            $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($candidate)) {
                return Directory::assertPathWithin($candidate, $root);
            }
        }

        throw new ApiException('当前仅支持本地文件安装插件包');
    }

    /** 从请求中解析插件标识。 */
    protected function getPluginName(Request $request): string
    {
        $name = $request->post('name', '');
        if (!$name && $request->post('id')) {
            $plugin = $this->model->findOrEmpty($request->post('id'));
            if (!$plugin->isEmpty()) {
                $name = $plugin->name;
            }
        }
        if (!$name) {
            throw new ApiException('插件标识不能为空');
        }
        $name = \normalizePluginName((string)$name);
        if (!preg_match('/^am_[a-z][a-z0-9_]*$/', $name)) {
            throw new ApiException('插件标识格式不正确');
        }
        return $name;
    }

    /** 构建自定义插件保存数据。 */
    protected function buildCustomPluginData(array $data, ?Plugin $plugin = null): array
    {
        return PluginManifestService::buildCustomPluginData($this->normalizeJsonFields($data), $plugin);
    }

    /** 规范化表单中的 JSON 字段。 */
    protected function normalizeJsonFields(array $data): array
    {
        foreach (['dependencies', 'frontend_routes', 'manifest', 'remote_vue'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = json_decode($data[$field], true);
                $data[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        return $data;
    }

    /** 规范化插件可见范围。 */
    protected function normalizePluginAuth(mixed $auth): array
    {
        $auth = \normalizeArray($auth);

        return [
            'user_ids' => normalizeIds($auth['user_ids'] ?? []),
            'dept_ids' => normalizeIds($auth['dept_ids'] ?? []),
            'tags_ids' => normalizeIds($auth['tags_ids'] ?? []),
        ];
    }


    /** 返回补全元信息后的插件列表。 */
    protected function returnPluginListData(mixed $list): Response
    {
        if (is_array($list)) {
            $data = array_map(fn($plugin) => PluginManifestService::enrichPlugin($plugin), $list);
            return success('', $data, count($data), 1);
        }

        $page = $list->toArray();
        $data = array_map(fn($plugin) => PluginManifestService::enrichPlugin($plugin), $page['data']);
        return success('', $data, $list->total(), $list->currentPage());
    }
}
