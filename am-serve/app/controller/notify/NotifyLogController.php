<?php

namespace app\controller\notify;

use app\basic\BaseController;
use app\model\notify\NotifyLog;
use app\service\NotifyService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/notify/log')]
class NotifyLogController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new NotifyLog();
    }

    #[Get('/index')]
    #[Permission(title: '发送记录列表', slug: 'notify:log:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['template_code', ''],
            ['keywords', ''],
            ['status', ''],
        ]);
        $list = $this->getList($this->search($this->model, $where));
        return $this->returnListData($list);
    }

    #[Get('/read')]
    #[Permission(title: '发送记录详情', slug: 'notify:log:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $log = $this->model->findOrEmpty($id);
        if (!$log) {
            return warning('记录不存在');
        }

        return success('', $log);
    }

    #[Post('/retry')]
    #[Permission(title: '重发失败记录', slug: 'notify:log:retry')]
    public function retry(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $log = $this->model->findOrEmpty($id);
        if (!$log) {
            return warning('记录不存在');
        }

        if (empty($log->template_code)) {
            return warning('记录缺少模板编码，无法重发');
        }

        // 解析各通道结果，找出失败的通道
        $channels = $log->channels ?? [];
        $failedReceivers = [];

        // channels 格式: {"email":{"status":2,"message":"...","cost_time":100}, ...}
        // 需要从 receiver 字段解析各通道接收方（简单场景：重发时重新获取用户信息）
        foreach ($channels as $type => $ch) {
            if (($ch['status'] ?? 0) === 2) {
                // 标记为重试（实际接收方需从原记录或用户信息获取）
                $failedReceivers[$type] = true;
            }
        }

        if (empty($failedReceivers)) {
            return warning('没有失败的通道需要重发');
        }

        // 更新重试次数
        NotifyLog::where('id', $log->id)->update([
            'retry_count' => ($log->retry_count ?? 0) + 1,
        ]);

        // 注意：重发时 receiver 信息需要从原记录推断
        // 简化处理：返回提示，让前端传入具体的通道和接收方
        return warning('请使用指定通道重发接口：传入 channel 和 receiver 参数');
    }

    #[Post('/retryChannel')]
    #[Permission(title: '重发指定通道', slug: 'notify:log:retryChannel')]
    public function retryChannel(Request $request): Response
    {
        $id = $request->post('id');
        $channel = $request->post('channel');
        $receiver = $request->post('receiver');

        if (empty($id) || empty($channel) || empty($receiver)) {
            return warning('参数错误：需要 id、channel、receiver');
        }

        $log = $this->model->findOrEmpty($id);
        if (!$log) {
            return warning('记录不存在');
        }

        // 重新发送指定通道
        $result = NotifyService::send(
            $log->template_code,
            $channel,
            $receiver
        );

        // 更新重试次数
        NotifyLog::where('id', $log->id)->update([
            'retry_count' => ($log->retry_count ?? 0) + 1,
        ]);

        if ($result['success']) {
            return success('重发成功', $result);
        }

        return warning('重发失败：' . ($result['message'] ?? ''));
    }

    #[Get('/stats')]
    #[Permission(title: '发送统计', slug: 'notify:log:stats')]
    public function stats(Request $request): Response
    {
        $days = (int) $request->get('days', 7);

        $since = strtotime("-{$days} days");

        $query = $this->model->where('create_time', '>=', $since);

        // 总体统计
        $total = $query->count();
        $successCount = (clone $query)->where('status', 1)->count();
        $failCount = (clone $query)->where('status', 2)->count();

        // 按日期统计（int 时间戳需用 FROM_UNIXTIME 转换）
        $dateStats = $this->model
            ->where('create_time', '>=', $since)
            ->field('DATE(FROM_UNIXTIME(create_time)) as date, count(*) as total, sum(status=1) as success_count, sum(status=2) as fail_count')
            ->group('DATE(FROM_UNIXTIME(create_time))')
            ->order('date asc')
            ->select()
            ->toArray();

        return success('', [
            'total' => $total,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'date_stats' => $dateStats,
        ]);
    }
}
