<?php

namespace app\controller\plugin;

use app\basic\BaseController;
use app\model\plugin\App as Plugin;
use app\model\plugin\Widget;
use app\service\FileService;
use app\service\Permission;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Request;
use support\Response;

class WidgetController extends BaseController
{
    /** 初始化小组件控制器依赖。 */
    public function initialize(): void
    {
        $this->model = new Widget();
    }
#[Get('/plugin/widget/index')]
    #[Permission(title: '小组件列表', slug: 'system:plugin:widget:index')]
    public function index(Request $request): Response
    {
        $appName = $request->get('app_name', '');
        $pluginId = $request->get('plugin_id', $request->get('id', ''));
        $filters = [
            ['name', ''],
            ['cate', ''],
            ['type', ''],
            ['status', ''],
        ];

        if ($appName !== '' || $pluginId !== '') {
            $plugin = Plugin::requireByAppIdOrId($appName, $pluginId);
            array_unshift($filters, ['app_name', $plugin->name]);
        }

        $where = $request->buildMap($filters);
        $list = $this->search($this->model, $where)->order('id', 'desc')->select()->toArray();
        $data = array_map(fn($item) => Widget::formatRow($item), $list);
        return success('', $data, count($data));
    }
#[Get('/plugin/widget/read')]
    #[Permission(title: '小组件详情', slug: 'system:plugin:widget:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('小组件不存在');
        }
        return success('', Widget::formatRow($info->toArray()));
    }
#[Post('/plugin/widget/save')]
    #[Permission(title: '创建小组件', slug: 'system:plugin:widget:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        $plugin = Plugin::requireDevByAppIdOrId($data['app_name'] ?? '', $data['plugin_id'] ?? ($data['id'] ?? ''), '仅开发模式插件允许管理小组件');
        $data['app_name'] = $plugin->name;
        $data['url'] = FileService::widgetUrl($plugin->name, $data['name'], $data['cate']);
        Widget::assertUniqueName($plugin, $data['name']);

        $widget = $this->transaction(function () use ($plugin, $data) {
            FileService::ensureWidgetFile($plugin->name, $data['name'], (int)$data['cate']);
            $this->model->save($data);
            return $this->model->toArray();
        });

        return success('保存成功', Widget::formatRow($widget));
    }
#[Post('/plugin/widget/update')]
    #[Permission(title: '更新小组件', slug: 'system:plugin:widget:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('小组件不存在');
        }
        $plugin = Plugin::requireDevByAppIdOrId($info->app_name, '', '仅开发模式插件允许管理小组件');

        $data = $request->post();
        if (isset($data['name']) && (string)$data['name'] !== (string)$info->name) {
            return warning('小组件标识不可修改');
        }
        if (isset($data['cate']) && (int)$data['cate'] !== (int)$info->cate) {
            return warning('小组件类型不可修改');
        }

        $saveData = array_merge($info->toArray(), $data);
        $widget = $this->transaction(function () use ($plugin, $info, $saveData) {
            FileService::ensureWidgetFile($plugin->name, $saveData['name'], (int)$saveData['cate']);
            $info->save([
                'title' => $saveData['title'],
                'description' => $saveData['description'],
                'type' => $saveData['type'],
                'url' => $saveData['url'],
                'size' => $saveData['size'],
                'status' => $saveData['status'],
            ]);
            return $info->toArray();
        });

        return success('保存成功', Widget::formatRow($widget));
    }
#[Post('/plugin/widget/delete')]
    #[Permission(title: '删除小组件', slug: 'system:plugin:widget:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('小组件不存在');
        }
        Plugin::requireDevByAppIdOrId($info->app_name, '', '仅开发模式插件允许管理小组件');

        $this->transaction(function () use ($info) {
            $info->delete();
        });

        return success('删除成功');
    }
}