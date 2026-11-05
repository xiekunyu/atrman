<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\cache\DictCache;
use app\model\system\Dict;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/dict')]
class DictController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Dict();
    }
    
    /**
     * 字典列表
     */
    #[Get('/index')]
    #[Permission(title: '字典列表', slug: 'system:dict:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['code', ''],
            ['name', ''],
            ['value', ''],
            ['keywords', ''],
        ]);
        $pid=$request->get('pid')??0;
        $map=['pid'=>$pid];
        $model=$this->search($this->model,$where);
        if($pid==0){
            $data=$model->where($map)->order('sort asc')->select()->toArray();
            return success('', $data);
        }else{
            $list = $this->getList($this->search($model, $where)->where($map));
            return $this->returnListData($list);
        }
    }

    /**
     * 用户详情
     */
    #[Get('/read')]
    #[Permission(title: '字典详情', slug: 'system:dict:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('字典不存在');
        }
        $data = $info->toArray();

        return success('', $data);
    }

    /**
     * 创建字典
     */
    #[Post('/save')]
    #[Permission(title: '创建字典', slug: 'system:dict:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if(isset($data['id'])){
            unset($data['id']);
        }
        $this->model->save($data);
        DictCache::clear();
        return success('保存成功');
    }

    /**
     * 更新字典
     */
    #[Post('/update')]
    #[Permission(title: '更新字典', slug: 'system:dict:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('字典不存在');
        }

        if ($info['is_sys'] == 1) {
            return warning('内置字典不能修改');
        }
        
        $data = $request->post();
        // pid不能修改
        unset($data['pid']);

        $result = $info->save($data);
        if (!$result) {
            return warning('更新字典失败');
        }
        
        DictCache::clear();
        return success('更新字典成功', $info);
    }


    /**
     * 删除字典
     */
    #[Post('/delete')]
    #[Permission(title: '删除字典', slug: 'system:dict:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('字典不存在');
        }

        if ($info['is_sys'] == 1) {
            return warning('内置字典不能删除');
        }
        $result = $info->delete();
        if (!$result) {
            return warning('删除字典失败');
        }
        DictCache::clear();

        return success('删除字典成功');
    }

    /**
     * 批量删除字典
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除字典', slug: 'system:dict:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }
        $list= $this->model->whereIn('id',$ids)->select();
        if($list->count()!==count($ids)){
            return warning('部分字典不存在');
        }
        foreach($list as $item){
            if($item['is_sys']==1){
                unset($ids[$item['id']]);
            }
        }
        
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });
        
        DictCache::clear();
        return success('删除成功');
    }
}
