<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\cache\ConfigCache;
use app\model\system\Config;
use app\validate\system\ConfigValidate;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/config')]
class ConfigController extends BaseController
{
    public function initialize(): void
    {
        $this->validate = new ConfigValidate();
        $this->model = new Config();
    }

    #[Get('/index')]
    #[Permission(title: '配置列表', slug: 'system:config:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['name', ''],
            ['key', ''],
            ['keywords', ''],
            ['app_name', ''],
        ]);
        $list = $this->getList($this->search($this->model, $where)->where(['pid'=>0]));
        return $this->returnListData($list);
    }

    #[Get('/read')]
    #[Permission(title: '配置详情', slug: 'system:config:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $config = $this->model->findOrEmpty($id);
        if (!$config) {
            return warning('配置不存在');
        }

        return success('', $config);
    }

    #[Post('/save')]
    #[Permission(title: '创建配置', slug: 'system:config:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();

        $saveData = [
            'pid' => 0,
            'name' => $data['name'],
            'code' => $data['code'],
            'app_name' => $data['app_name'] ?? '',
        ];

        $result = $this->model->create($saveData);
        if (!$result) {
            return warning('添加失败');
        }
        ConfigCache::clearConfig((string) $data['code'], (string) ($data['app_name'] ?? ''));
        return success('添加成功', $result);
    }

    #[Post('/update')]
    #[Permission(title: '更新配置', slug: 'system:config:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('配置不存在');
        }

        if ($info['is_sys'] == 1) {
            return warning('内置配置不能修改');
        }

        $data = $request->post();

        if (!$this->validate->scene('save')->check($data)) {
            return warning($this->validate->getError());
        }
         $saveData = [
            'name' => $data['name'],
            'code' => $data['code']
        ];
        $oldCode = $info->code;
        $result = $info->save($saveData);
        if (!$result) {
            return warning('更新失败');
        }
        ConfigCache::clearConfig((string) $oldCode, (string) ($info->app_name ?? ''));
        ConfigCache::clearConfig((string) $data['code'], (string) ($info->app_name ?? ''));

        return success('更新成功', $info);
    }

    #[Post('/delete')]
    #[Permission(title: '删除配置', slug: 'system:config:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('配置不存在');
        }

        if ($info['is_sys'] == 1) {
            return warning('内置配置不能删除');
        }

        $code = $info->code;
        $appName = (string) ($info->app_name ?? '');
        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }
        ConfigCache::clearConfig((string) $code, $appName);

        return success('删除成功');
    }

    #[Post('/batchDelete')]
    #[Permission(title: '批量删除配置', slug: 'system:config:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }

        $sysCount = $this->model->whereIn('id', $ids)->where('is_sys', 1)->count();
        if ($sysCount > 0) {
            return warning('部分数据是内置配置，不能删除');
        }

        $rows = $this->model->whereIn('id', $ids)->field('code,app_name')->select();
        
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });
        
        foreach ($rows as $row) {
            ConfigCache::clearConfig((string) $row->code, (string) ($row->app_name ?? ''));
        }

        return success('删除成功');
    }

    #[Post('/getConfig')]
    #[Permission(title: '获取数据列表', slug: 'system:config:getConfig')]
    public function getConfig(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('配置不存在');
        }
        $list=$this->model->where('pid',$id)->order('sort asc')->select()->toArray();
        foreach($list as $k=>$item){
            $list[$k]=Config::parseConfigItem($item);
        }
        $info->list=$list;
        return success('',$info);
    }

    #[Post('/setConfig')]
    #[Permission(title: '保存配置数据', slug: 'system:config:setConfig')]
    public function setConfig(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('配置不存在');
        }
        $oldIds=$this->model->where('pid',$id)->column('id');
        $data = $request->post();
        $items=$data['items'] ?? [];
        if($items){
            $newIds=array_filter(array_column($items,'id'));
            $keyList=array_column($items,'key');
            // 验证key标识是否重复
            if(count($keyList) !== count(array_unique($keyList))){
                return warning('字段标识不能重复');
            }
            $createList=[];
            foreach($items as $k=>$item){
                $itemId=$item['id'] ?? 0;
                if($itemId){
                    $hasItem=$this->model->findOrEmpty($itemId);
                    if(!$hasItem){
                        continue;
                    }
                    if($hasItem['is_sys']==1){
                        // 内置配置不允许编辑，跳过
                        continue;
                    }
                    $update=[
                        'name'=>$item['name'],
                        'code'=>$item['key'],
                        'sort'=>$k,
                        'form_type'=>$item['type'] ?? 'input',
                        'options'=>$item,
                    ];
                    $this->model->where('id',$item['id'])->update($update);
                }else{
                    $createList[]=[
                        'pid'=>$id,
                        'name'=>$item['name'],
                        'code'=>$item['key'],
                        'value'=>$item['defaultValue'] ?? '',
                        'sort'=>$k,
                        'form_type'=>$item['type'] ?? 'input',
                        'options'=>$item,
                    ];
                }
            }
            // 批量插入
            if($createList){
                $this->model->saveAll($createList);
            }
            // 取差集，删除被删除的配置（内置配置不允许删除，跳过）
            $diffIds=array_values(array_diff($oldIds,$newIds));
            if($diffIds){
               $sysIds=$this->model->whereIn('id',$diffIds)->where('is_sys',1)->column('id');
               $diffIds=array_values(array_diff($diffIds,$sysIds));
               if($diffIds){
                   $this->model->destroy($diffIds);
               }
            }
        }
        ConfigCache::clearConfig((string) $info->code, (string) ($info->app_name ?? ''));
        return success('保存成功', $info);
    }

    #[Post('/saveConfig')]
    #[Permission(title: '保存配置项', slug: 'system:config:saveConfig')]
    public function saveConfig(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }
        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('配置不存在');
        }
        $data = $request->post();
        $params=$data['params'] ?? [];
        if($params){
           foreach($params as $k=>$v){
                $value=is_array($v) ? json_encode($v) : $v;
                $this->model->where(['code'=>$k,'pid'=>$id])->update(['value'=>$value]);
           }
        }
        ConfigCache::clearConfig((string) $info->code, (string) ($info->app_name ?? ''));
        return success('保存成功', $info);
    }
}
