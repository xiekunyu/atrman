<?php

namespace app\controller\notify;

use app\basic\BaseController;
use app\model\notify\SmsProvider;
use app\model\system\Config;
use app\validate\notify\SmsProviderValidate;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/notify/sms-provider')]
class SmsProviderController extends BaseController
{
    public function initialize(): void
    {
        $this->validate = new SmsProviderValidate();
        $this->model = new SmsProvider();
    }

    #[Get('/index')]
    #[Permission(title: '短信供应商列表', slug: 'notify:sms-provider:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['name', ''],
            ['keywords', ''],
            ['status', ''],
        ]);
        $model = $this->search($this->model, $where);
        $list = $model->with(['configInfo'])->select()->toArray();
        return success('', $list, count($list));
    }

    #[Get('/read')]
    #[Permission(title: '短信供应商详情', slug: 'notify:sms-provider:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $provider = $this->model->with(['configInfo'])->findOrEmpty($id);
        if (!$provider) {
            return warning('供应商配置不存在');
        }

        return success('', $provider);
    }

    #[Post('/save')]
    #[Permission(title: '创建短信供应商', slug: 'notify:sms-provider:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();

        if (!$this->validate->scene('save')->check($data)) {
            return warning($this->validate->getError());
        }

        // 从配置表解析 code（easy-sms 网关标识）
        $code = Config::where(['id' => $data['type'] ?? 0])->value('code');
        $data['code'] = $code ?? '';

        $result = $this->model->save($data);
        if (!$result) {
            return warning('创建失败');
        }

        return success('创建成功', $result);
    }

    #[Post('/update')]
    #[Permission(title: '更新短信供应商', slug: 'notify:sms-provider:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('供应商配置不存在');
        }

        $data = $request->post();

        if (!$this->validate->scene('update')->check($data)) {
            return warning($this->validate->getError());
        }

        // 从配置表解析 code（easy-sms 网关标识）
        $code = Config::where(['id' => $data['type'] ?? 0])->value('code');
        $data['code'] = $code ?? '';

        $result = $info->save($data);
        if (!$result) {
            return warning('更新失败');
        }

        return success('更新成功', $info);
    }

    #[Post('/delete')]
    #[Permission(title: '删除短信供应商', slug: 'notify:sms-provider:delete')]
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
            return warning('内置供应商配置不能删除');
        }

        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        return success('删除成功');
    }

    #[Post('/batchDelete')]
    #[Permission(title: '批量删除短信供应商', slug: 'notify:sms-provider:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }

        $sysCount = $this->model->whereIn('id', $ids)->where('is_sys', 1)->count();
        if ($sysCount > 0) {
            return warning('部分供应商配置是内置配置，不能删除');
        }

        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });

        return success('删除成功');
    }

    #[Post('/changeStatus')]
    #[Permission(title: '切换供应商状态', slug: 'notify:sms-provider:changeStatus')]
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

    #[Post('/setDefault')]
    #[Permission(title: '设为默认供应商', slug: 'notify:sms-provider:setDefault')]
    public function setDefault(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('数据不存在');
        }

        // 先取消所有默认
        $this->model->where('is_default', 1)->update(['is_default' => 0]);

        // 设为默认
        $info->is_default = 1;
        $result = $info->save();
        if (!$result) {
            return warning('设置默认失败');
        }

        return success('设置默认成功');
    }

    #[Post('/test')]
    #[Permission(title: '测试发送短信', slug: 'notify:sms-provider:test')]
    public function test(Request $request): Response
    {
        $id = $request->post('id');
        $phone = $request->post('phone');

        if (empty($id) || empty($phone)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('供应商配置不存在');
        }

        try {
            $easySms = new \Overtrue\EasySms\EasySms([
                'timeout' => 5.0,
                'default' => [
                    'strategy' => \Overtrue\EasySms\Strategies\OrderStrategy::class,
                    'gateways' => [$info->code],
                ],
                'gateways' => [
                    $info->code => (array)$info->config,
                ],
            ]);

            $result = $easySms->send($phone, [
                'template' => 'SMS_498500262',
                'data' => [
                    'code' => '123456',
                ],
            ]);

            return success('测试发送成功', $result);
        } catch (\Exception $e) {
            return warning('测试发送失败：' . $e->getMessage());
        }
    }

    #[Get('/all')]
    #[Permission(title: '获取所有启用的供应商', slug: 'notify:sms-provider:all')]
    public function all(Request $request): Response
    {
        $list = $this->model->where('status', 1)
            ->field('id,name,code')
            ->order('is_default desc, id asc')
            ->select()
            ->toArray();

        return success('', $list);
    }
}
