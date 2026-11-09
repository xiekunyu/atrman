<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\cache\UserAuthCache;
use app\cache\UserInfoCache;
use app\cache\UserMenuCache;
use app\model\system\Menu;
use app\model\system\Role;
use app\model\system\RoleDept;
use app\model\system\RoleMenu;
use app\model\system\User;
use app\model\system\UserRole;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;
use app\exception\ApiException;

#[RouteGroup('/sys/role')]
class RoleController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Role();
    }
    /**
     * 角色列表
     */
    #[Get('/index')]
    #[Permission(title: '角色列表', slug: 'system:role:index')]
    public function index(Request $request): Response
    {
         $where = $request->buildMap([
            ['status', ''],
            ['keywords', ''],
        ]);
        $list = $this->getList($this->search($this->model, $where));
        return $this->returnListData($list);
    }

    /**
     * 角色详情
     */
    #[Get('/read')]
    #[Permission(title: '角色详情', slug: 'system:role:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }

        // 获取角色关联的菜单（直查中间表，与 getPermissions 口径一致；ThinkORM Query 无 pluck）
        $menuIds = RoleMenu::where('role_id', (int) $id)->column('menu_id');
        $info->menu_ids = $menuIds;
        // 自定义数据权限部门（回显）
        $info->custom_dept_ids = RoleDept::where('role_id', (int) $id)->column('dept_id');

        return success('', $info);
    }

    /**
     * 创建角色
     */
    #[Post('/save')]
    #[Permission(title: '创建角色', slug: 'system:role:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // 行政级别：新建角色级别须严格低于自己
        $this->assertCanManage((int) ($data['level'] ?? 0));

        // 新建角色：Model::save() 只返回 bool，插入后的主键在模型实例上
        if (!$this->model->save($data)) {
            return warning('创建角色失败');
        }
        $roleId = (int) $this->model->id;

        // 关联菜单
        if (isset($data['menu_ids']) && !empty($data['menu_ids'])) {
            $this->model->menus()->attach($this->expandMenuIds((array) $data['menu_ids']));
        }

        // 自定义数据权限部门
        $this->syncRoleDept($roleId, (int) ($data['data_scope'] ?? 2), $data['custom_dept_ids'] ?? []);

        return success('创建角色成功', $this->model);
    }

    /**
     * 更新角色
     */
    #[Post('/update')]
    #[Permission(title: '更新角色', slug: 'system:role:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }

        // 行政级别：只能操作严格低于自己级别的现有角色，且不得提升到 >= 自己
        $this->assertCanManage((int) $info->level);
        $newLevel = $request->post('level');
        if ($newLevel !== null && $newLevel !== '') {
            $this->assertCanManage((int) $newLevel);
        }

        $data = $request->post();

        // 更新角色
        $result = $info->save($data);
        if (!$result) {
            return warning('更新角色失败');
        }

        // 更新菜单关联
        if (isset($data['menu_ids'])) {
            $info->menus()->sync($this->expandMenuIds((array) $data['menu_ids'], (int) $id));
        }

        // 自定义数据权限部门
        $this->syncRoleDept((int) $id, (int) ($data['data_scope'] ?? 2), $data['custom_dept_ids'] ?? []);

        UserInfoCache::clearByRole((int) $id);
        UserAuthCache::clearByRole((int) $id);
        UserMenuCache::clear();
        return success('更新角色成功', $info);
    }

    /**
     * 删除角色
     */
    #[Post('/delete')]
    #[Permission(title: '删除角色', slug: 'system:role:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }

        // 行政级别
        $this->assertCanManage((int) $info->level);

        $this->transaction(function () use ($info, $id) {
            // 删除角色与菜单的关联
            $info->menus()->detach();
            // 删除角色与用户的关联
            $info->users()->detach();
            // 删除自定义数据权限部门
            RoleDept::where('role_id', (int) $id)->delete();

            // 删除角色
            $result = $info->delete();
            if (!$result) {
                throw new ApiException('删除角色失败');
            }
        });
        UserInfoCache::clearByRole((int) $id);
        UserAuthCache::clearByRole((int) $id);
        UserMenuCache::clear();
        return success('删除角色成功');
    }

    /**
     * 批量删除角色
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除角色', slug: 'system:role:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }
        $list=$this->model->whereIn('id',$ids)->select();
        if($list->count()!==count($ids)){
            return warning('部分角色不存在');
        }
        // 行政级别：逐个校验
        foreach ($list as $role) {
            $this->assertCanManage((int) $role->level);
        }
        $this->transaction(function () use ($list,$ids) {
            // 删除角色与菜单的关联
            $list->each(function ($role) {
                $role->menus()->detach();
                $role->users()->detach();
            });
            RoleDept::whereIn('role_id', $ids)->delete();
            $this->model->destroy($ids);
        });
        UserInfoCache::clearByRole($ids);
        UserAuthCache::clearByRole($ids);
        UserMenuCache::clear();
        return success('删除角色成功');
    }

    /**
     * 切换角色状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换角色状态', slug: 'system:role:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }

        // 行政级别
        $this->assertCanManage((int) $info->level);
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }
        UserInfoCache::clearByRole((int) $id);
        UserAuthCache::clearByRole((int) $id);
        UserMenuCache::clear();

        return success('切换状态成功');
    }

    /**
     * 获取所有角色
     */
    #[Get('/getAllRoles')]
    #[Permission(title: '获取所有角色', slug: 'system:role:getAllRoles')]
    public function getAllRoles(Request $request): Response
    {
        $list = $this->model->where('status', 1)->select()->toArray();
        return success('获取角色列表成功', $list);
    }

    /**
     * 获取角色菜单
     */
    #[Get('/getPermissions')]
    #[Permission(title: '角色菜单', slug: 'system:role:getPermissions')]
    public function getPermissions(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }
        $menuIds = RoleMenu::where(['role_id' => $id])->column('menu_id');
        return success('', [
            'id' => $id,
            'menu_ids' => $menuIds,
        ]);
    }

    /**
     * 保存角色菜单
     */
    #[Post('/setPermissions')]
    #[Permission(title: '保存角色菜单', slug: 'system:role:setPermissions')]
    public function setPermissions(Request $request): Response
    {
        $id = $request->input('id');
        $menu_ids = $request->input('menu_ids');
        if (!is_array($menu_ids)) {
            // 未传该字段时旧实现会 detach 后不挂载，即"静默清空授权并提示保存成功"
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('角色不存在');
        }
        // 行政级别
        $this->assertCanManage((int) $info->level);
        $menuIds = $this->expandMenuIds($menu_ids, (int) $id);
        // 事务操作
        $this->transaction(function () use ($info, $menuIds, $id) {
            // 先解除所有关联
            $info->menus()->detach();

            // 关联菜单
            if (!empty($menuIds)) {
               $data = array_map(function ($menu_id) use ($id) {
                        return ['menu_id' => $menu_id, 'role_id' => $id];
                    }, $menuIds);
               (new RoleMenu())->insertAll($data);
            }
        });
        UserInfoCache::clearByRole((int) $id);
        UserAuthCache::clearByRole((int) $id);
        UserMenuCache::clear();
        return success('保存成功');
    }

    /**
     * 授权菜单集合的服务端兜底：剔除库中不存在的 id，并逐级补全父菜单。
     *
     * 授权树默认非关联模式、且前端只提交勾选节点，所以「只勾子页面」会让父目录缺失、
     * 菜单在侧栏不可达；父级补全必须在这里做，不能依赖前端级联。
     *
     * 提交节点先与操作者自身持有的权限节点求交（超管除外），保证授权只能往下传、不能往上造。
     *
     * 传 $roleId 时额外保留该角色已有的、当前处于停用态的菜单授权（如禁用中的插件入口，
     * 见 PluginManager::disableEntryMenu）：授权树只列启用菜单，这些 id 操作者提交不出来，
     * 不保留就会把「临时禁用插件」变成「永久丢授权」。
     */
    private function expandMenuIds(array $menuIds, int $roleId = 0): array
    {
        $menuIds = array_values(array_unique(array_filter(array_map('intval', $menuIds), fn($id) => $id > 0)));
        // 授权上界：只允许授出自己已持有的权限节点。否则持有 system:role:setPermissions 的管理员
        // 可以把 system:plugin:install、system:backup:restore 这类整棵树上不过滤的 slug 授给下级角色，
        // 等价于自封超管。父级目录在下方补全，它们不带 slug、不产生授权，不受这里影响。
        if (!isSuperAdmin()) {
            $own = UserAuthCache::getUserAuth(currentUserId());
            $grantable = $own
                ? array_map('intval', Menu::where('type', 3)->whereIn('slug', $own)->column('id'))
                : [];
            $menuIds = array_values(array_intersect($menuIds, $grantable));
        }
        if ($roleId > 0) {
            $unsubmitted = array_diff(array_map('intval', RoleMenu::where('role_id', $roleId)->column('menu_id')), $menuIds);
            if ($unsubmitted) {
                $menuIds = array_merge($menuIds, Menu::whereIn('id', $unsubmitted)->where('status', '<>', 1)->column('id'));
            }
        }
        if (!$menuIds) {
            return [];
        }
        // id => pid，逐轮把未收集的父 id 补进来；库里不存在的父级自然不进表（菜单树已禁止成环）
        $pidMap = Menu::whereIn('id', $menuIds)->column('pid', 'id');
        $pending = array_keys($pidMap);
        while ($pending) {
            $parentIds = array_unique(array_map(fn($id) => (int)($pidMap[$id] ?? 0), $pending));
            $parentIds = array_filter($parentIds, fn($pid) => $pid > 0 && !isset($pidMap[$pid]));
            if (!$parentIds) {
                break;
            }
            $newRows = Menu::whereIn('id', $parentIds)->column('pid', 'id');
            $pidMap = $pidMap + $newRows;
            $pending = array_keys($newRows);
        }
        return array_keys($pidMap);
    }

    /**
     * 获取可操作角色
     */
    #[Get('/accessRole')]
    #[Permission(title: '获取可操作角色', slug: 'system:role:accessRole')]
    public function accessRole(Request $request): Response
    {
        // 越权保护：仅返回严格低于自己级别的角色（超管返回全部）
        $query = $this->model->field('id,id as value, name,name as label, code, remark, level, sort');
        if (!isSuperAdmin()) {
            $query->where('level', '<', currentLevel());
        }
        $data = $query->order('sort', 'desc')->select()->toArray();
        return success('', $data);
    }

    /**
     * 获取角色的用户列表
     */
    #[Get('/getUsers')]
    #[Permission(title: '获取角色的用户列表', slug: 'system:role:getUsers')]
    public function getUsers(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('角色不存在');
        }
        // 直查中间表：Role::where(['role_id'=>$id])->users() 的 where 条件不会带进关联查询，
        // 实际会返回全站用户（与 read() 取 menu_ids 的口径保持一致）
        $userIds = UserRole::where('role_id', (int) $id)->column('user_id');
        $list = $userIds ? User::whereIn('id', $userIds)->hidden(['password', 'salt'])->select()->toArray() : [];
        return success('', $list);
    }

    /**
     * 同步角色自定义数据权限部门（仅 data_scope=5 生效）
     */
    private function syncRoleDept(int $roleId, int $dataScope, array $customDeptIds): void
    {
        RoleDept::where('role_id', $roleId)->delete();
        if ($dataScope !== 5 || empty($customDeptIds)) {
            return;
        }
        $deptIds = array_values(array_unique(array_filter(array_map('intval', $customDeptIds), fn($d) => $d > 0)));
        if (!$deptIds) {
            return;
        }
        $rows = array_map(fn($d) => ['role_id' => $roleId, 'dept_id' => $d], $deptIds);
        (new RoleDept())->insertAll($rows);
    }
}
