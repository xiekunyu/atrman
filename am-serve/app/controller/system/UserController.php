<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\basic\EventRegistry;
use app\cache\UserInfoCache;
use app\model\system\User;
use app\model\system\Dept;
use app\model\system\Tags;
use app\model\system\DeptUser;
use app\model\system\UserRole;
use app\model\system\Role;
use app\validate\system\UserValidate;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/user')]
class UserController extends BaseController
{
    /**
     * 只由服务端决定的账号字段：
     * sid 是跨端统一主体标识、account_type 决定能否进后台，两者被前端回传即可伪造身份；
     * last_login_* 属登录轨迹，created/updated_by 属审计字段。
     */
    private const SERVER_OWNED_FIELDS = ['sid', 'salt', 'account_type', 'last_login_time', 'last_login_ip'];

    public function initialize(): void
    {
        $this->validate = new UserValidate();
        $this->model = new User();
    }
    
    /**
     * 用户列表
     */
    #[Get('/index')]
    #[Permission(title: '用户列表', slug: 'system:user:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['username', ''],
            ['nickname', ''],
            ['mobile', ''],
            ['email', ''],
            ['status', ''],
            ['gender', ''],
            ['create_time', ''],
            ['keywords', ''],
        ]);
        $dept_id = $request->get('dept_id', '');
        $tags_id = $request->get('tags_id', '');
        $map = [];
        $depUserList = [];
        // 查询部门成员
        if ($dept_id) {
            $deptList = Dept::getAllChild($dept_id);
            $dept_ids = array_column($deptList, 'id');
            $user_ids = DeptUser::where([['dept_id', 'in', $dept_ids]])->column('user_id');
            $map = [['id', 'in', $user_ids]];
            // 查询部门上级
            $depUser = DeptUser::where([['dept_id', '=', $dept_id]])->select()->toArray();
            if ($depUser) {
                foreach ($depUser as $item) {
                    $depUserList[$item['user_id']] = $item;
                }
            }
            
        }
        if ($tags_id) {
            $user_ids = Tags::getTagUserIds($tags_id);
            $map = [['id', 'in', $user_ids]];
        }
        $model = $this->model->withoutField(['password', 'salt'])->with(['dept' => function ($query) {
            $query->field('id,name');
        }, 'avatar' => function ($query) {
            $query->field('id,path,url,thumb');
        }]);
        // 统一主体：基座成员管理默认只展示管理员（account_type=1），会员中心才展示全部人员
        $accountType = (int) $request->get('account_type', User::ACCOUNT_ADMIN);
        $model->where('account_type', $accountType ?: User::ACCOUNT_ADMIN);
        $list = $this->getList($this->search($model->where($map), $where));
        $data = $list->toArray()['data'];
        foreach ($data as $k => $v) {
            $data[$k]['is_cur_dept'] = 0;
            if ($dept_id) {
                $depUser = $depUserList[$v['id']] ?? [];
                if ($depUser) {
                    $data[$k]['is_leader'] = $depUser['is_leader'] ?? 0;
                    $data[$k]['cur_dept_id'] = $dept_id ?? 0;
                    $data[$k]['is_cur_dept'] = 1;
                }
            } else {
                $data[$k]['is_leader'] = 0;
                $data[$k]['cur_dept_id'] = 0;
            }
            
        }
        return success('', $data, $list->total(), $list->currentPage());
    }

    /**
     * 用户详情
     */
    #[Get('/read')]
    #[Permission(title: '用户详情', slug: 'system:user:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->with(['avatar'])->findOrEmpty($id);
        if (!$info) {
            return warning('用户不存在');
        }

        // 行政级别：同级或更高级别的账号详情不可读（与 update/delete/editPass 同口径，
        // 否则仅有 system:user:read 的账号能逐个 id 摸出所有人的角色与部门）
        $this->assertCanManage($this->userLevel((int) $id), (int) $id);

        $data = $info->hidden(['password','salt'])->toArray();
        $roles = $info->roles;
        $data['role_ids'] = $roles->column('id') ?: [];
        $data['roles'] = $roles->visible(['id','name','code'])->toArray();
        $deptUsers = DeptUser::where('user_id', $id)->select()->toArray();
        $deptIds = array_column($deptUsers, 'dept_id');
        $deptMap = [];
        if (!empty($deptIds)) {
            $deptList = Dept::whereIn('id', $deptIds)->field('id,name')->select()->toArray();
            foreach ($deptList as $dept) {
                $deptMap[$dept['id']] = $dept;
            }
        }
        $data['dept_ids'] = $deptIds;
        $data['dept_items'] = [];
        $data['depts'] = [];
        foreach ($deptUsers as $deptUser) {
            $dept = $deptMap[$deptUser['dept_id']] ?? [];
            if (empty($dept)) {
                continue;
            }
            $dept['is_main'] = (int) ($deptUser['is_main'] ?? 0);
            $dept['stype'] = 'dept';
            $dept['key'] = 'dept_' . $dept['id'];
            $data['depts'][] = $dept;
            $data['dept_items'][] = ['id' => $dept['id'], 'is_main' => $dept['is_main']];
        }

        return success('', $data);
    }

    /**
     * 创建用户
     */
    #[Post('/save')]
    #[Permission(title: '创建用户', slug: 'system:user:save')]
    public function save(Request $request): Response
    {
        // 主体标识与登录轨迹由服务端生成，绝不允许前端回传覆盖
        $data = $this->stripServerFields($request->post(), self::SERVER_OWNED_FIELDS);

        // 密码加密
        $data['salt'] = \app\utils\Str::random(4);
        $data['password'] = passwordHash($data['password'], $data['salt']);
        // 头像统一取首个文件 id：未传/传字符串时直接下标访问会报错（与 update 同口径）
        if (array_key_exists('avatar_id', $data)) {
            $data['avatar_id'] = is_array($data['avatar_id']) ? ($data['avatar_id'][0] ?? null) : $data['avatar_id'];
        }
        // 检查用户名是否已存在
        $existingUser = User::where('username', $data['username'])->find();
        if ($existingUser) {
            return warning('用户名已存在');
        }
        // 生成sid
        $data['sid'] = \app\utils\Hashids::getSid('sys');
        // 后台新建用户统一为管理员身份（会员/普通成员由会员中心等入口以 account_type=成员 写入）
        $data['account_type'] = User::ACCOUNT_ADMIN;
        // 行政级别：只能分配严格低于自己级别的角色
        $this->assertRolesManageable((array) ($data['role_ids'] ?? []));
        $userId = 0;
        $this->transaction(function () use ($data, &$userId) {
            // 创建用户
            $user = User::create($data);
            $userId = (int)$user->id;
            $role_ids = $data['role_ids'] ?? [];
            $dept_id = $data['dept_id'] ?? 0;
            // 创建部门成员
            DeptUser::create([
                'user_id'=>$user->id,
                'dept_id'=>$dept_id,
                'is_main'=>1,
            ]);
            $user->roles()->detach();
            $user->roles()->attach($role_ids);
        });
        EventRegistry::emit('user.created', [
            'user_id' => $userId,
            'sid' => (string)$data['sid'],
            'username' => (string)$data['username'],
        ]);
        return success('保存成功');
    }

    /**
     * 更新用户
     */
    #[Post('/update')]
    #[Permission(title: '更新用户', slug: 'system:user:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('用户不存在');
        }

        // 行政级别：只能操作有效级别严格低于自己的账号
        $this->assertCanManage($this->userLevel((int) $id), (int) $id);

        // 主体标识与登录轨迹由服务端生成，绝不允许前端回传覆盖
        $data = $this->stripServerFields($request->post(), self::SERVER_OWNED_FIELDS);
        $hasRoleIds = array_key_exists('role_ids', $data);
        $roleIds = $hasRoleIds ? (array) $data['role_ids'] : [];
        // 行政级别：只能分配严格低于自己级别的角色
        $this->assertRolesManageable($roleIds);
        unset($data['role_ids']);

        $hasDeptId = array_key_exists('dept_id', $data);
        $deptId = $hasDeptId ? (int) $data['dept_id'] : 0;

        // 如果修改密码，需要加密
        if (isset($data['password']) && !empty($data['password'])) {
            $data['salt'] = \app\utils\Str::random(4);
            $data['password'] = passwordHash($data['password'], $data['salt']);
        } else {
            unset($data['password']);
        }
        if (array_key_exists('avatar_id', $data)) {
            $data['avatar_id'] = is_array($data['avatar_id']) ? ($data['avatar_id'][0] ?? null) : $data['avatar_id'];
        }
        $this->transaction(function () use ($data, $info, $hasRoleIds, $roleIds, $hasDeptId, $deptId) {
            if ($hasDeptId) {
                $deptMap = [
                    'user_id' => $info->id,
                    'is_main' => 1
                ];
                $hasMainDept = DeptUser::where($deptMap)->find();
                if ($hasMainDept) {
                    // 更新部门成员
                    DeptUser::where($deptMap)->update([
                        'dept_id' => $deptId,
                    ]);
                } else {
                    // 创建部门成员
                    DeptUser::create([
                        'user_id' => $info->id,
                        'dept_id' => $deptId,
                        'is_main' => 1,
                    ]);
                }
            }
            // 更新用户
            $info->save($data);
            if ($hasRoleIds) {
                $info->roles()->detach();
                if (!empty($roleIds)) {
                    $info->roles()->attach($roleIds);
                }
            }
        });
        UserInfoCache::clearUserAll((int) $id);
        return success('更新用户成功', $info);
    }

    /**
     * 设置用户部门
     */
    #[Post('/setDepts')]
    #[Permission(title: '设置用户部门', slug: 'system:user:setDepts')]
    public function setDepts(Request $request): Response
    {
        $id = $request->post('id');
        $deptIds = $request->post('dept_ids', []);
        $deptItems = $request->post('dept_items', []);
        if (empty($id) || empty($deptIds) || !is_array($deptIds)) {
            return warning('参数错误');
        }
        if ((int) $id === 1) {
            return warning('超级管理员不能修改部门');
        }
        $user = $this->model->findOrEmpty($id);
        if (!$user) {
            return warning('用户不存在');
        }
        $deptIds = array_values(array_filter(array_unique($deptIds), fn($deptId) => (int) $deptId > 0));
        if (empty($deptIds)) {
            return warning('请选择部门');
        }
        $deptCount = Dept::whereIn('id', $deptIds)->count();
        if ((int) $deptCount !== count($deptIds)) {
            return warning('部分部门不存在');
        }
        $mainDeptId = 0;
        foreach ($deptItems as $item) {
            if (!empty($item['is_main']) && in_array($item['id'], $deptIds)) {
                $mainDeptId = $item['id'];
                break;
            }
        }
        if (!$mainDeptId) {
            $mainDeptId = $deptIds[0];
        }
        $this->transaction(function () use ($id, $deptIds, $mainDeptId) {
            DeptUser::where('user_id', $id)->delete();
            foreach ($deptIds as $deptId) {
                DeptUser::create([
                    'user_id' => $id,
                    'dept_id' => $deptId,
                    'is_main' => $deptId == $mainDeptId ? 1 : 0,
                ]);
            }
            User::where('id', $id)->update(['dept_id' => $mainDeptId]);
        });
        UserInfoCache::clearUserInfo((int) $id);

        return success('设置部门成功');
    }

    /**
     * 批量设置用户信息
     */
    #[Post('/batchSet')]
    #[Permission(title: '批量设置用户信息', slug: 'system:user:batchSet')]
    public function batchSet(Request $request): Response
    {
        $type = $request->post('type');
        $userIds = $request->post('user_ids', []);
        if (empty($type) || empty($userIds) || !is_array($userIds)) {
            return warning('参数错误');
        }
        if (in_array(1, $userIds)) {
            return warning('超级管理员不能批量设置');
        }
        $userCount = $this->model->whereIn('id', $userIds)->count();
        if ((int) $userCount !== count($userIds)) {
            return warning('部分用户不存在');
        }

        // 行政级别：批量操作目标须严格低于自己
        foreach ($userIds as $uid) {
            $this->assertCanManage($this->userLevel((int) $uid), (int) $uid);
        }

        if ($type === 'dept') {
            $deptIds = $request->post('dept_ids', []);
            $deptItems = $request->post('dept_items', []);
            if (empty($deptIds) || !is_array($deptIds)) {
                return warning('请选择部门');
            }
            $deptIds = array_values(array_filter(array_unique($deptIds), fn($id) => (int) $id > 0));
            if (empty($deptIds)) {
                return warning('请选择部门');
            }
            $deptCount = Dept::whereIn('id', $deptIds)->count();
            if ((int) $deptCount !== count($deptIds)) {
                return warning('部分部门不存在');
            }
            $mainDeptId = 0;
            foreach ($deptItems as $item) {
                if (!empty($item['is_main']) && in_array($item['id'], $deptIds)) {
                    $mainDeptId = $item['id'];
                    break;
                }
            }
            if (!$mainDeptId) {
                $mainDeptId = $deptIds[0];
            }
            $this->transaction(function () use ($userIds, $deptIds, $mainDeptId) {
                foreach ($userIds as $userId) {
                    DeptUser::where('user_id', $userId)->delete();
                    foreach ($deptIds as $deptId) {
                        DeptUser::create([
                            'user_id' => $userId,
                            'dept_id' => $deptId,
                            'is_main' => $deptId == $mainDeptId ? 1 : 0,
                        ]);
                    }
                    User::where('id', $userId)->update(['dept_id' => $mainDeptId]);
                }
            });
            UserInfoCache::clearUserInfo($userIds);
            return success('批量设置部门成功');
        }

        if ($type === 'role') {
            $roleIds = $request->post('role_ids', []);
            if (empty($roleIds) || !is_array($roleIds)) {
                return warning('请选择角色');
            }
            $this->assertRolesManageable($roleIds);
            $this->transaction(function () use ($userIds, $roleIds) {
                foreach ($userIds as $userId) {
                    UserRole::where('user_id', $userId)->delete();
                    $user = User::findOrEmpty($userId);
                    $user->roles()->attach($roleIds);
                }
            });
            UserInfoCache::clearUserAll($userIds);
            return success('批量设置角色成功');
        }

        if ($type === 'status') {
            $status = $request->post('status');
            if ($status === '') {
                return warning('请选择状态');
            }
            if (!in_array((string) $status, ['0', '1'], true)) {
                return warning('状态必须是0或1');
            }
            User::whereIn('id', $userIds)->update(['status' => (int) $status]);
            UserInfoCache::clearUserInfo($userIds);
            return success('批量设置状态成功');
        }

        if ($type === 'password') {
            $data = ['password' => $request->post('password', '')];
            if (!$this->validate->scene('editPass')->check($data)) {
                return warning($this->validate->getError());
            }
            $this->transaction(function () use ($userIds, $data) {
                foreach ($userIds as $userId) {
                    $salt = \app\utils\Str::random(4);
                    User::where('id', $userId)->update([
                        'salt' => $salt,
                        'password' => passwordHash($data['password'], $salt),
                    ]);
                }
            });
            UserInfoCache::clearUserAll($userIds);
            return success('批量设置密码成功');
        }

        return warning('设置类型错误');
    }

    /**
     * 修改密码
     * 管理员修改密码可以无任何限制
     */
    #[Post('/editPass')]
    #[Permission(title: '修改密码', slug: 'system:user:editPass')]
    public function editPass(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('用户不存在');
        }

        // 行政级别
        $this->assertCanManage($this->userLevel((int) $id), (int) $id);

        $data = $request->post();

        // 验证数据
        if (!$this->validate->scene('editPass')->check($data)) {
            return warning($this->validate->getError());
        }
        // 密码加密
        $data['salt'] = \app\utils\Str::random(4);
        $data['password'] = passwordHash($data['password'], $data['salt']);
        // 更新用户
        $result = $info->save($data);
        if (!$result) {
            return warning('修改密码失败');
        }
        UserInfoCache::clearUserAll((int) $id);

        return success('修改密码成功', $info);
    }

    /**
     * 删除用户
     */
    #[Post('/delete')]
    #[Permission(title: '删除用户', slug: 'system:user:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        // 禁止自删
        if ((int)$id === currentUserId()) {
            return warning('不能删除自己的账号');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('用户不存在');
        }

        // 行政级别
        $this->assertCanManage($this->userLevel((int) $id), (int) $id);

        // 删除前捕获 sid，供删除事件同步下游（如会员中心软删对应会员行）
        $sid = (string)$info->sid;
        // 删除用户角色关联
        $info->roles()->detach();
        $result = $info->delete();
        if (!$result) {
            return warning('删除用户失败');
        }
        UserInfoCache::clearUserAll((int) $id);

        EventRegistry::emit('user.deleted', [
            'user_id' => (int)$id,
            'sid' => $sid,
        ]);

        return success('删除用户成功');
    }

    /**
     * 批量删除用户
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除用户', slug: 'system:user:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }
        $ids = array_map('intval', (array)$ids);
        // 禁止批量包含当前账号
        if (in_array(currentUserId(), $ids, true)) {
            return warning('批量列表中包含当前登录账号');
        }
        $list = $this->model->whereIn('id', $ids)->select();
        if ($list->count() !== count($ids)) {
            return warning('部分用户不存在');
        }
        // 行政级别：逐个校验
        foreach ($ids as $uid) {
            $this->assertCanManage($this->userLevel((int) $uid), (int) $uid);
        }
        // 删除前采集 id=>sid，事务提交后逐条发删除事件（载荷与 user.created 同构）
        $sidMap = $this->model->whereIn('id', $ids)->column('sid', 'id');
        $this->transaction(function () use ($ids) {
            foreach ($ids as $id) {
                $userRoles = $this->model->findOrEmpty($id);
                $userRoles->roles()->detach();
            }
            $this->model->destroy($ids);
        });
        UserInfoCache::clearUserAll($ids);
        foreach ($sidMap as $uid => $sid) {
            EventRegistry::emit('user.deleted', [
                'user_id' => (int)$uid,
                'sid' => (string)$sid,
            ]);
        }
        return success('删除用户成功');
    }

    /**
     * 切换用户状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换用户状态', slug: 'system:user:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }
        $id = (int)$id;

        // 禁止自禁
        if ($id === currentUserId() && (int)$status !== 1) {
            return warning('不能禁用自己的账号');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('用户不存在');
        }

        // 行政级别
        $this->assertCanManage($this->userLevel($id), $id);
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }
        UserInfoCache::clearUserInfo((int) $id);

        return success('切换状态成功');
    }

    /** 用户有效行政级别 = 其所有角色 level 最大值 */
    private function userLevel(int $uid): int
    {
        $roleIds = UserRole::where('user_id', $uid)->column('role_id');
        if (!$roleIds) {
            return 0;
        }
        return (int) Role::whereIn('id', $roleIds)->max('level');
    }

    /** 分配角色时：被分配角色的最高级别须严格低于自己 */
    private function assertRolesManageable(array $roleIds): void
    {
        if (empty($roleIds) || isSuperAdmin()) {
            return;
        }
        $maxLevel = (int) Role::whereIn('id', $roleIds)->max('level');
        $this->assertCanManage($maxLevel);
    }
}
