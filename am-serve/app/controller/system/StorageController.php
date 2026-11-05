<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\Storage;
use app\model\system\Config;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/storage')]
class StorageController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Storage();
    }

    #[Get('/index')]
    #[Permission(title: '存储列表', slug: 'system:storage:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['name', ''],
            ['type', ''],
            ['status', ''],
            ['keywords', ''],
        ]);
        $model=$this->search($this->model,$where);
        $list = $model->with(['configInfo'])->select()->toArray();
        return success('', $list,count($list));
    }

    #[Get('/read')]
    #[Permission(title: '存储详情', slug: 'system:storage:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $storage = $this->model->findOrEmpty($id);
        if (!$storage) {
            return warning('存储配置不存在');
        }

        return success('', $storage);
    }

    #[Post('/save')]
    #[Permission(title: '创建存储', slug: 'system:storage:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }

        $adapter = Config::where(['id' => $data['type']])->value('code');
        $data['adapter'] = $adapter ?? 'local';
        $result = $this->model->save($data);
        if (!$result) {
            return warning('创建失败');
        }
        return success('创建成功', $result);
    }

    #[Post('/update')]
    #[Permission(title: '更新存储', slug: 'system:storage:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('存储配置不存在');
        }
        $data = $request->post();
        $adapter = Config::where(['id' => $data['type']])->value('code');
        $data['adapter'] = $adapter ?? 'local';
        $result = $info->save($data);
        if (!$result) {
            return warning('更新失败');
        }

        return success('更新成功', $info);
    }

    #[Post('/delete')]
    #[Permission(title: '删除存储', slug: 'system:storage:delete')]
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

        if ($info['is_sys'] == 1) {
            return warning('内置存储配置不能删除');
        }

        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        return success('删除成功');
    }

    #[Post('/batchDelete')]
    #[Permission(title: '批量删除存储', slug: 'system:storage:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }

        $sysCount = $this->model->whereIn('id', $ids)->where('is_sys', 1)->count();
        if ($sysCount > 0) {
            return warning('部分存储配置是内置配置，不能删除');
        }

        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });

        return success('删除成功');
    }

    #[Post('/changeStatus')]
    #[Permission(title: '切换存储状态', slug: 'system:storage:changeStatus')]
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
