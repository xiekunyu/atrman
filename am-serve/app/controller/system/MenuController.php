<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\basic\PluginRegistrar;
use app\cache\UserAuthCache;
use app\cache\UserInfoCache;
use app\cache\UserMenuCache;
use app\exception\ApiException;
use app\model\plugin\App as PluginApp;
use app\model\system\Menu;
use app\model\system\RoleMenu;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;
use app\service\PluginManager;

#[RouteGroup('/sys/menu')]
class MenuController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Menu();
    }
    /**
     * 菜单列表
     */
    #[Get('/index')]
    #[Permission(title: '菜单列表', slug: 'system:menu:index')]
    public function index(Request $request): Response
    {
        $where=$request->buildMap([
            ['name', ''],
            ['route', ''],
        ]);
        $model=$this->search($this->model,$where);
        if ($request->input('tree', 'false') === 'true') {
            $model->field(Menu::$treeField);
            $list=$model->order('sort asc')->where([['type','<',3]])->select()->toArray();
            $data=\app\utils\Tree::createListToTree($list,'id','pid','children');
            $data=[['id'=>0,'label'=>'顶级菜单','pid'=>0,'value'=>0,'children'=>$data]];
            return success('', $data);
        }
        $list=$model->order('sort asc')->select()->toArray();
        $data=\app\utils\Tree::createListToTree($list,'id','pid','children');
        return success('', $data);
    }
    

    /**
     * 菜单详情
     */
    #[Get('/read')]
    #[Permission(title: '菜单详情', slug: 'system:menu:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('菜单不存在');
        }

        return success('获取菜单详情成功', $info);
    }

    /**
     * 创建菜单
     */
    #[Post('/save')]
    #[Permission(title: '创建菜单', slug: 'system:menu:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }
        // 插件“管理入口”菜单（保留 code app:entry:%）的图标由插件自身维护，不接受手工提交
        if (str_starts_with((string)($data['code'] ?? ''), PluginManager::ENTRY_CODE_PREFIX)) {
            unset($data['icon']);
        }
        
        // 创建菜单
        $info = $this->model->save($data);
        if (!$info) {
            return warning('创建菜单失败');
        }
        UserMenuCache::clear();
        UserAuthCache::clear();
        UserInfoCache::clear();

        return success('创建菜单成功', $info);
    }

    /**
     * 更新菜单
     */
    #[Post('/update')]
    #[Permission(title: '更新菜单', slug: 'system:menu:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('菜单不存在');
        }

        $data = $request->post();

        // 插件“管理入口”菜单（保留 code app:entry:%）的图标由插件自身维护，编辑时不可修改
        if (str_starts_with((string)$info->code, PluginManager::ENTRY_CODE_PREFIX)) {
            unset($data['icon']);
        }

        // 检查是否为自己的子菜单
        if (isset($data['pid']) && $data['pid'] == $id) {
            return warning('父菜单不能是自己');
        }

        // 检查是否为子菜单的子菜单（避免循环）
        if (isset($data['pid']) && $data['pid']) {
            $children = $info->where('pid',$id)->column('id');
            if (in_array($data['pid'], $children)) {
                return warning('父菜单不能是当前菜单的子菜单');
            }
        }

        // 更新菜单
        $result = $info->save($data);
        if (!$result) {
            return warning('更新菜单失败');
        }
        UserMenuCache::clear();
        UserAuthCache::clear();
        UserInfoCache::clear();

        return success('更新菜单成功', $data);
    }

    /**
     * 删除菜单
     */
    #[Post('/delete')]
    #[Permission(title: '删除菜单', slug: 'system:menu:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('菜单不存在');
        }

        $this->assertDeletable([(int)$id]);

        // 删除菜单
        $result = $info->delete();
        if (!$result) {
            return warning('删除菜单失败');
        }
        UserMenuCache::clear();
        UserAuthCache::clear();
        UserInfoCache::clear();

        return success('删除菜单成功');
    }

    /**
     * 批量删除菜单
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除菜单', slug: 'system:menu:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids) || !is_array($ids)) {
            return warning('参数错误');
        }

        // 检查数据是否存在
        $list = $this->model->whereIn('id', $ids)->select();
        if ($list->count() !== count($ids)) {
            return warning('部分菜单不存在');
        }

        $this->assertDeletable(array_map('intval', $ids));

        // 批量删除菜单
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });
        
        UserMenuCache::clear();
        UserAuthCache::clear();
        UserInfoCache::clear();

        return success('删除菜单成功');
    }

    /**
     * 切换菜单状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换菜单状态', slug: 'system:menu:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('菜单不存在');
        }
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }
        UserMenuCache::clear();
        UserAuthCache::clear();
        UserInfoCache::clear();

        return success('切换状态成功');
    }

    /**
     * 获取菜单树
     */
    #[Get('/getMenuTree')]
    #[Permission(title: '获取菜单树', slug: 'system:menu:getMenuTree')]
    public function getMenuTree(Request $request): Response
    {
        // 超级管理员查询所有菜单，普通用户只查询分配的菜单
        if (isSuperAdmin()) {
            // 超级管理员：查询所有启用的菜单
            $menus = $this->model->where(['status' => 1])
                ->field(Menu::$treeField)
                ->order('sort', 'asc')
                ->select()
                ->toArray();
        }else{
            // 普通用户：查询角色分配的菜单
            $roleIds = $this->currentRoleIds();
            if(empty($roleIds)){
                return success('', []);
            }
            
            // 获取角色关联的菜单ID
            $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
            $menuIds = array_unique($menuIds);
            
            if(empty($menuIds)){
                return success('', []);
            }
            
            // 查询分配的菜单
            $menus = $this->model->where([
                    ['status', '=', 1],
                    ['id', 'in', $menuIds]
                ])
                ->field(Menu::$treeField)
                ->order('sort', 'asc')
                ->select()
                ->toArray();
        }
            
        $tree = \app\utils\Tree::createTreeByList($menus,0,1,'id','pid','children');
        return success('', $tree);
    }

    /**
     * 扫描控制器同步按钮权限节点（type=3）
     *
     * 目录与页面仍由使用者在菜单里自己维护，本接口只补齐/校正按钮层：
     * slug 在库里缺一条，非超管就永久 403（见 AuthCheck）。默认不删孤儿，
     * 孤儿只进报告——删按钮会连带回收 role_menu 授权，需人工确认后再带 prune=1 执行。
     */
    #[Post('/syncPermission')]
    #[Permission(title: '同步按钮权限', slug: 'system:menu:syncPermission')]
    public function syncPermission(Request $request): Response
    {
        $prune = (int)$request->post('prune', 0) === 1;
        $summary = [];
        foreach ($this->permissionScopes() as $scope) {
            $report = PluginRegistrar::syncPermissionsFromControllers($scope['app_name'], $scope['targets'], [
                'page_map' => $scope['page_map'],
                'prune' => $prune,
            ]);
            $summary[] = [
                'app_name' => $scope['app_name'] === '' ? 'base' : $scope['app_name'],
                'created' => count($report['created']),
                'updated' => count($report['updated']),
                'unchanged' => $report['unchanged'],
                'removed' => count($report['removed']),
                'orphan' => $report['orphan'],
                'unattached' => $report['unattached'],
                'ambiguous' => $report['ambiguous'],
                'conflict' => $report['conflict'],
                'warnings' => count($report['warnings']) + count($report['unannotated']),
            ];
        }
        // 报告由前端按归属逐条展示，这里不再带 msg，避免 http 层的通用成功提示与报告重复弹窗
        return success('', $summary);
    }

    /**
     * 同步范围：基座 + 已启用插件的控制器目录。
     *
     * page_map 只解决一件事：某些子功能的按钮在代码里没有任何归属线索（部门/标签是用户页的功能、
     * 存储库是文件页的功能、插件与市场按钮各挂自己的页面），notify 的 log 段还会与「操作日志」
     * 的 path 末段撞车。这类关系必须显式声明，推不出的一律留在 unattached 报告里，不猜父级。
     */
    private function permissionScopes(): array
    {
        $scopes = [[
            'app_name' => '',
            'targets' => [base_path() . '/app/controller'],
            'page_map' => [
                'AppController' => 'myPlugin',
                'WidgetController' => 'myPlugin',
                'StoreController' => 'store',
                'DeptController' => 'user',
                'TagsController' => 'user',
                'StorageController' => 'FileStation',
                'NotifyLogController' => 'notify_log',
                'NotifyPreferenceController' => 'inbox',
            ],
        ]];
        foreach (PluginApp::where('status', 1)->select() as $plugin) {
            $name = (string)$plugin->name;
            $dir = base_path() . '/plugin/' . $name . '/app/controller';
            if ($name !== '' && is_dir($dir)) {
                $scopes[] = ['app_name' => $name, 'targets' => [$dir], 'page_map' => []];
            }
        }
        return $scopes;
    }

    /**
     * 按插件查询该插件自己的菜单树（供插件子应用侧栏使用）
     * GET /sys/menu/pluginMenu?app_name=am_todo
     */
    #[Get('/pluginMenu')]
    public function pluginMenu(Request $request): Response
    {
        $appName = \normalizePluginName((string)$request->get('app_name', ''));
        if ($appName === '') {
            return success('', []);
        }
        // 与 PluginStatusCheck 同口径：只有启用中的插件才对外提供菜单，
        // 禁用/卸载后仍返回旧菜单会让子应用侧栏停在打不开的死链路上
        if (PluginApp::where('name', $appName)->where('status', 1)->count() < 1) {
            return success('', []);
        }
        $isSuper = isSuperAdmin();
        $menus = $this->model->getPluginMenus($appName, $this->currentRoleIds(), $isSuper);
        return success('', resolveMenuIcons($menus));
    }

    /**
     * 删除前置校验（单删/批删共用）。
     *
     * 子菜单必须一起出现在本次删除集合里，否则父级被删后子级挂在失效 pid 上，
     * 授权树与侧栏都再也拼不出它们，只能靠人工改库恢复。
     */
    private function assertDeletable(array $ids): void
    {
        // 检查是否有子菜单
        $children = Menu::whereIn('pid', $ids)->column('id');
        if (array_diff(array_map('intval', $children), $ids)) {
            throw new ApiException('该菜单下还有子菜单，无法删除');
        }

        // 检查是否有角色关联
        if (RoleMenu::whereIn('menu_id', $ids)->count() > 0) {
            throw new ApiException('该菜单下还有角色关联，无法删除');
        }
    }

    /**
     * 当前登录者的角色 ID。
     *
     * 必须查库：JWT 载荷（BaseController::$userInfo）不含 role_ids，直接读会恒为空数组，
     * 导致非超管无论是否被授予权限都拿不到菜单。与基座侧栏 UserMenuCache 取同一来源，
     * 顺带避免"改完权限要等重新登录"的陈旧判定。
     */
    private function currentRoleIds(): array
    {
        return UserAuthCache::roleIds((int)($this->userInfo['id'] ?? 0));
    }
}
