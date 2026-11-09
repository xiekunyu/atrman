<?php

namespace app\controller\notify;

use app\basic\BaseController;
use app\model\notify\NotifyTemplate;
use app\model\system\User;
use app\service\NotifyService;
use app\service\TemplateEngine;
use app\validate\notify\NotifyTemplateValidate;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/notify/template')]
class NotifyTemplateController extends BaseController
{
    public function initialize(): void
    {
        $this->validate = new NotifyTemplateValidate();
        $this->model = new NotifyTemplate();
    }

    #[Get('/index')]
    #[Permission(title: '模板列表', slug: 'notify:template:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['name', ''],
            ['code', ''],
            ['keywords', ''],
            ['source', ''],
            ['status', ''],
        ]);
        $list = $this->getList($this->search($this->model, $where));
        return $this->returnListData($list);
    }

    #[Get('/read')]
    #[Permission(title: '模板详情', slug: 'notify:template:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $template = $this->model->findOrEmpty($id);
        if (!$template) {
            return warning('模板不存在');
        }

        return success('', $template);
    }

    #[Post('/save')]
    #[Permission(title: '创建模板', slug: 'notify:template:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();

        if (!$this->validate->scene('save')->check($data)) {
            return warning($this->validate->getError());
        }

        // 检查 code 唯一性
        $exists = $this->model->where('code', $data['code'])
            ->find();
        if ($exists) {
            return warning('该模板编码已存在');
        }

        $result = $this->model->create($data);
        if (!$result) {
            return warning('创建失败');
        }

        return success('创建成功', $result);
    }

    #[Post('/update')]
    #[Permission(title: '更新模板', slug: 'notify:template:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('模板不存在');
        }

        $data = $request->post();

        if (!$this->validate->scene('update')->check($data)) {
            return warning($this->validate->getError());
        }

        $result = $info->save($data);
        if (!$result) {
            return warning('更新失败');
        }

        return success('更新成功', $info);
    }

    #[Post('/delete')]
    #[Permission(title: '删除模板', slug: 'notify:template:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('模板不存在');
        }
        
        // 检查是否有发送记录引用该模板
        $logCount = \app\model\notify\NotifyLog::where('template_id', $id)->count();
        if ($logCount > 0) {
            return warning('该模板已有发送记录，无法删除');
        }

        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        return success('删除成功');
    }

    #[Post('/batchDelete')]
    #[Permission(title: '批量删除模板', slug: 'notify:template:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }
        
        // 检查是否有发送记录引用这些模板
        $logCount = \app\model\notify\NotifyLog::whereIn('template_id', $ids)->count();
        if ($logCount > 0) {
            return warning('部分模板已有发送记录，无法删除');
        }

        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });

        return success('删除成功');
    }

    #[Post('/changeStatus')]
    #[Permission(title: '切换模板状态', slug: 'notify:template:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('模板不存在');
        }

        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }

        return success('切换状态成功');
    }

    #[Post('/preview')]
    #[Permission(title: '预览模板渲染', slug: 'notify:template:preview')]
    public function preview(Request $request): Response
    {
        $content = $request->post('content', '');
        $smsContent = $request->post('sms_content', '');
        $title = $request->post('title', '');
        $variables = $request->post('variables', []);

        if (empty($content) && empty($smsContent)) {
            return warning('模板内容不能为空');
        }

        // 构建测试变量值
        $testVars = [];
        if (is_array($variables)) {
            foreach ($variables as $var) {
                $name = $var['name'] ?? '';
                if ($name) {
                    $testVars[$name] = $var['test_value'] ?? '[' . ($var['label'] ?? $name) . ']';
                }
            }
        }

        $systemVars = TemplateEngine::getSystemVariables();

        return success('', [
            'title' => TemplateEngine::render($title, $testVars, $systemVars),
            'content' => TemplateEngine::render($content, $testVars, $systemVars),
            'sms_content' => TemplateEngine::render($smsContent, $testVars, $systemVars),
        ]);
    }

    #[Post('/test')]
    #[Permission(title: '测试发送模板', slug: 'notify:template:test')]
    public function test(Request $request): Response
    {
        $id = $request->post('id');
        $variables = $request->post('variables', []);
        $overrideEmail = $request->post('email', '');
        $overridePhone = $request->post('phone', '');

        if (empty($id)) {
            return warning('参数错误');
        }

        $template = $this->model->findOrEmpty($id);
        if (!$template) {
            return warning('模板不存在');
        }

        // 获取当前登录用户信息
        $userInfo = getCurrentInfo();
        $userId = (int)($userInfo['id'] ?? 0);
        if (!$userId) {
            return warning('未登录');
        }

        // 构建变量值
        $testVars = [];
        if (is_array($variables)) {
            foreach ($variables as $var) {
                $name = $var['name'] ?? '';
                if ($name) {
                    $testVars[$name] = $var['test_value'] ?? $var['value'] ?? '';
                }
            }
        }

        $results = [];
        $email = $overrideEmail ?: '';
        $phone = $overridePhone ?: '';

        // 构建接收方映射（各通道独立指定接收方，为空则跳过）
        $receiverMap = [];
        if (!empty($email) && !empty($template->content)) {
            $receiverMap['email'] = $email;
        }
        $receiverMap['inbox'] = $userId;
        if (!empty($phone) && (!empty($template->sms_template_id) || !empty($template->sms_content))) {
            $receiverMap['sms'] = $phone;
        }

        // 一次调用，一条日志记录所有通道的发送结果
        $results = NotifyService::sendMulti($template->code, $receiverMap, $testVars);

        // 移除可能的 _error 键（无可用通道时的错误）
        $error = $results['_error'] ?? null;
        unset($results['_error']);
        if ($error && empty($results)) {
            return warning($error['message'] ?? '发送失败');
        }

        return success('测试发送完成', $results);
    }

    #[Post('/extractVariables')]
    #[Permission(title: '提取模板变量', slug: 'notify:template:extractVariables')]
    public function extractVariables(Request $request): Response
    {
        $content = $request->post('content', '');
        if (empty($content)) {
            return warning('模板内容不能为空');
        }

        $vars = TemplateEngine::extractVariables($content);
        return success('', $vars);
    }
}
