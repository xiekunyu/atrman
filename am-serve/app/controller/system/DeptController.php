<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\cache\UserInfoCache;
use app\model\system\Dept;
use app\model\system\DeptUser;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/dept')]
class DeptController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Dept();
    }
    /**
     * 部门列表
     */
    #[Get('/index')]
    #[Permission(title: '部门列表', slug: 'system:dept:index')]
    public function index(Request $request): Response
    {
        $where=$request->buildMap([
            ['name', '']
        ]);
        $model=$this->search($this->model,$where);
        if ($request->input('tree', 'false') === 'true') {
            $model->field(Dept::$treeField);
        }
        $list=$model->order('sort asc')->select()->toArray();
        foreach($list as $k=>$v){
            $list[$k]['stype']='dept';
            $list[$k]['key']='dept_'.$v['id'];
        }
        $data=\app\utils\Tree::createListToTree($list,'id','pid','children');
        $data=[['id'=>0,'name'=>"顶级部门",'stype'=>'dept','label'=>"顶级部门",'pid'=>0,'value'=>0,'key'=>'dept_0','children'=>$data]];
        return success('',$data,count($list));
    }

    /**
     * 部门详情
     */
    #[Get('/read')]
    #[Permission(title: '部门详情', slug: 'system:dept:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('部门不存在');
        }

        return success('', $info);
    }

    /**
     * 创建部门
     */
    #[Post('/save')]
    #[Permission(title: '创建部门', slug: 'system:dept:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // 创建部门
        $info = $this->model->create($data);
        if (!$info) {
            return warning('创建部门失败');
        }

        return success('', $info);
    }

    /**
     * 更新部门
     */
    #[Post('/update')]
    #[Permission(title: '更新部门', slug: 'system:dept:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('部门不存在');
        }

        $data = $request->post();

        $pid = $data['pid'] ?? 0;
        $oldPid = $info->pid;

        // 检查是否为自己的子部门
        if ($pid == $id) {
            return warning('父部门不能是自己');
        }

        // 检查是否为子部门的子部门（避免循环）
        if ($pid) {
            $children = $this->getDeptChilds($id);
            if (in_array($pid, $children)) {
                return warning('父部门不能是当前部门的子部门');
            }
        }

        // 更新部门
        $result = $info->save($data);
        if (!$result) {
            return warning('更新部门失败');
        }

        $this->refreshDeptChildsByIds(array_merge([$id], $this->getDeptParentIds($oldPid), $this->getDeptParentIds($pid)));
        UserInfoCache::clearByDept((int) $id);

        return success('更新部门成功', $info);
    }

    /**
     * 删除部门
     */
    #[Post('/delete')]
    #[Permission(title: '删除部门', slug: 'system:dept:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('部门不存在');
        }

        // 检查是否有子部门
        if ($info->children()->count() > 0) {
            return warning('该部门下还有子部门，无法删除');
        }

        // 检查是否有用户
        if ($info->users()->count() > 0) {
            return warning('该部门下还有用户，无法删除');
        }

        $parentIds = $this->getDeptParentIds($info->pid);

        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        $this->refreshDeptChildsByIds($parentIds);
        UserInfoCache::clearByDept((int) $id);

        return success('删除成功');
    }

    /**
     * 批量删除部门
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除部门', slug: 'system:dept:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }

        // 检查是否有子部门
        $hasChildren = $this->model->whereIn('pid', $ids)->count() > 0;
        if ($hasChildren) {
            return warning('部分部门下还有子部门，无法删除');
        }

        // 检查是否有用户
        $hasUsers = $this->model->whereIn('id', $ids)->withCount('users')->having('users_count', '>', 0)->count() > 0;
        if ($hasUsers) {
            return warning('部分部门下还有用户，无法删除');
        }

        $parentIds = [];
        foreach ($this->model->whereIn('id', $ids)->column('pid') as $pid) {
            $parentIds = array_merge($parentIds, $this->getDeptParentIds($pid));
        }

        // 批量删除部门
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });

        $this->refreshDeptChildsByIds($parentIds);
        UserInfoCache::clearByDept($ids);

        return success('删除部门成功');
    }

    /**
     * 切换部门状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换部门状态', slug: 'system:dept:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('部门不存在');
        }
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }
        UserInfoCache::clearByDept((int) $id);

        return success('切换状态成功');
    }

    /**
     * 获取部门树
     */
    #[Get('/getDeptTree')]
    #[Permission(title: '获取部门树', slug: 'system:dept:getDeptTree')]
    public function getDeptTree(Request $request): Response
    {
        $depts = $this->model->where('status', 1)->order('sort', 'asc')->select()->toArray();
        $tree = $this->buildDeptTree($depts);
        return success('获取部门树成功', $tree);
    }

    /**
     * 构建部门树
     */
    private function buildDeptTree(array $depts, $parentId = 0)
    {
        $tree = [];
        foreach ($depts as $dept) {
            if ($dept['pid'] == $parentId) {
                $children = $this->buildDeptTree($depts, $dept['id']);
                if ($children) {
                    $dept['children'] = $children;
                }
                $tree[] = $dept;
            }
        }
        return $tree;
    }

    private function refreshDeptChildsByIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return;
        }

        $list = Dept::order('sort asc')->select()->toArray();
        foreach ($ids as $id) {
            Dept::update(['childs' => $this->getDeptChilds($id, $list)], ['id' => $id]);
        }
    }

    private function getDeptChilds(int $id, array $list = []): array
    {
        $list = $list ?: Dept::order('sort asc')->select()->toArray();
        $children = findAllChildren($id, $list, false);
        foreach ($children as $k => $child) {
            unset($child['childs']);
            $children[$k] = $child;
        }
        return array_column($children,'id');
    }

    private function getDeptParentIds(int $pid): array
    {
        $ids = [];
        while ($pid) {
            $dept = Dept::where('id', $pid)->field('id,pid')->find();
            if (!$dept || in_array($dept->id, $ids)) {
                break;
            }
            $ids[] = $dept->id;
            $pid = $dept->pid;
        }
        return $ids;
    }

    /**
     * 设置部门领导
     */
    #[Post('/setLeader')]
    #[Permission(title: '设置部门领导', slug: 'system:dept:setLeader')]
    public function setLeader(Request $request): Response
    {
        $data = $request->post();
        if (empty($data['user_id']) || '' === $data['dept_id']) {
            return warning('参数错误');
        }
        $dept_id=$data['dept_id'];
        $user_id=$data['user_id'];
        $deptUser=DeptUser::where([['dept_id','=',$dept_id],['user_id','=',$user_id]])->find();
        if($deptUser){
            $isLeader=!$deptUser->is_leader;
            DeptUser::update(['is_leader'=>$isLeader],['dept_id'=>$dept_id,'user_id'=>$user_id]);
        }else{
            DeptUser::create(['dept_id'=>$dept_id,'user_id'=>$user_id,'is_leader'=>1]);
        }
        UserInfoCache::clearUserInfo((int) $user_id);
        return success('设置成功');
    }
}
