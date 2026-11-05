<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\Tags;
use app\model\system\User;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/tags')]
class TagsController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Tags();
    }
    /**
     * 标签列表
     */
    #[Get('/index')]
    #[Permission(title: '标签列表', slug: 'system:tags:index')]
    public function index(Request $request): Response
    {
        $list = $this->model->select()->toArray();
        foreach($list as $k=>$v){
            $list[$k]['stype']='tags';
            $list[$k]['key']='tags_'.$v['id'];
        }
        return success('', $list);
    }

    // 获取标签组织架构列表
    #[Get('/read')]
    #[Permission(title: '标签详情', slug: 'system:tags:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('标签不存在');
        }
        $structure=[
            'user_ids'=>$info->user_ids,
            'dept_ids'=>$info->dept_ids,
        ];
        $structureList = User::getStructure($structure);
        $info->structure = $structure;
        $info->structureList = $structureList;
        return success('', $info);
    }

    /**
     * 创建标签
     */
    #[Post('/save')]
    #[Permission(title: '创建标签', slug: 'system:tags:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }
        
        // 创建标签
        $info = $this->model->create($data);
        if (!$info) {
            return warning('创建标签失败');
        }

        return success('保存成功', $info);
    }

    /**
     * 更新标签
     */
    #[Post('/update')]
    #[Permission(title: '更新标签', slug: 'system:tags:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('标签不存在');
        }

        $data = $request->post();
        $user_ids = $request->post('user_ids', []);
        $dept_ids = $request->post('dept_ids', []);
        if (!is_array($user_ids) || !is_array($dept_ids)) {
            return warning('参数错误');
        }

        // 更新标签
        $result = $info->save($data);
        if (!$result) {
            return warning('更新标签失败');
        }

        return success('更新成功', $info);
    }

    /**
     * 删除标签
     */
    #[Post('/delete')]
    #[Permission(title: '删除标签', slug: 'system:tags:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->find($id);
        if (!$info) {
            return warning('标签不存在');
        }

        // 删除标签
        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        return success('删除成功');
    }

}
