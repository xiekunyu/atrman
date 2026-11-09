<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\Remote;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/remote')]
class RemoteController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Remote();
    }
#[Get('/index')]
    #[Permission(title: '远程列表', slug: 'system:remote:index')]
    public function index(Request $request): Response
    {
         $where = $request->buildMap([
             ['name', ''],
             ['code', ''],
            ['keywords', ''],
        ]);
        $list = $this->getList($this->search($this->model, $where));
        return $this->returnListData($list);
    }
#[Get('/read')]
    #[Permission(title: '远程详情', slug: 'system:remote:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('数据不存在');
        }

        return success('', $info);
    }

    /**
     * 创建角色
     */
    #[Post('/save')]
    #[Permission(title: '创建远程数据', slug: 'system:remote:save')]
    public function save(Request $request): Response
    {
        // is_sys 是"基座内置数据"标记（决定能否被删除、能否被插件注册器同步覆盖），只能由服务端写
        $data = $this->stripServerFields($request->post(), ['is_sys', 'udpated_by']);

        // 开始事务
        $remote = $this->model->save($data);
        if (!$remote) {
            return warning('创建失败');
        }

        return success('创建成功', $remote);
    }

    /**
     * 更新角色
     */
    #[Post('/update')]
    #[Permission(title: '更新远程数据', slug: 'system:remote:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('数据不存在');
        }

        $data = $this->stripServerFields($request->post(), ['is_sys', 'udpated_by']);

        // 更新远程数据
        $result = $info->save($data);
        if (!$result) {
            return warning('更新失败');
        }
        return success('更新成功', $info);
    }

    /**
     * 删除角色
     */
    #[Post('/delete')]
    #[Permission(title: '删除远程数据', slug: 'system:remote:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('数据不存在');
        }
        if($info['is_sys']==1){
            return warning('系统数据不能删除');
        }
        $result = $this->model->destroy($id);
        if (!$result) {
            return warning('删除失败');
        }
        return success('删除成功');
    }

    /**
     * 批量删除角色
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除远程数据', slug: 'system:remote:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }
        $list=$this->model->whereIn('id',$ids)->select();
        // 过滤系统数据不能删除
        foreach($list as $info){
            if($info['is_sys']==1){
                unset($ids[$info['id']]);
                continue;
            }
        }
        
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });
        
        return success('删除数据成功');
    }

    /**
     * 切换角色状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换远程数据状态', slug: 'system:remote:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('数据不存在');
        }
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }

        return success('切换状态成功');
    }

   
}
