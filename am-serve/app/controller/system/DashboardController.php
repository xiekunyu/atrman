<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\plugin\App as PluginApp;
use app\model\system\File;
use app\model\system\SystemLog;
use app\model\system\User;
use app\model\system\UserLoginLog;
use app\utils\ServerMonitor;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use support\think\Cache;
use support\think\Db;

/**
 * 基座工作台统计接口
 *
 * 仅登录即可访问（不挂 Permission），供工作台本地小部件消费：
 * 核心指标、登录趋势、新增用户、系统动态、应用状态统计、存储用量、服务器健康。
 * 聚合统计统一走 Redis 短缓存，避免日志大表实时扫描。
 */
#[RouteGroup('/sys/dashboard')]
class DashboardController extends BaseController
{
    /** 指标缓存时间（秒） */
    private const CACHE_TTL = 30;
#[Get('/stats')]
    public function stats(Request $request): Response
    {
        $cacheKey = 'dashboard:stats';
        $data = Cache::get($cacheKey);
        if (is_array($data)) {
            return success('', $data);
        }

        [$todayStart, , , $weekStart, $lastWeekStart] = $this->timeBuckets();

        $data = [
            'users' => [
                'total' => User::count(),
                'today' => User::where('create_time', '>=', $todayStart)->count(),
                'week' => User::where('create_time', '>=', $weekStart)->count(),
                'last_week' => User::where('create_time', 'between', [$lastWeekStart, $weekStart - 1])->count(),
            ],
            'logins' => [
                'today' => UserLoginLog::where('create_time', '>=', $todayStart)->where('status', 1)->count(),
                'week' => UserLoginLog::where('create_time', '>=', $weekStart)->where('status', 1)->count(),
                'last_week' => UserLoginLog::where('create_time', 'between', [$lastWeekStart, $weekStart - 1])->where('status', 1)->count(),
            ],
            'active' => [
                'week' => Db::name('system_user_login_log')
                    ->where('create_time', '>=', $weekStart)
                    ->where('status', 1)
                    ->count('DISTINCT username'),
            ],
            'apps' => [
                'total' => PluginApp::count(),
                'enabled' => PluginApp::where('status', 1)->count(),
                'error' => PluginApp::where('status', -1)->count(),
            ],
            // 仅保留全部文件占用容量，供“存储用量”指标使用（文件总数已不在工作台展示）
            'files' => [
                'size' => (int)File::where('type', 2)->sum('size'),
            ],
            'operates' => [
                'today' => SystemLog::where('create_time', '>=', $todayStart)->count(),
                'week' => SystemLog::where('create_time', '>=', $weekStart)->count(),
                'last_week' => SystemLog::where('create_time', 'between', [$lastWeekStart, $weekStart - 1])->count(),
            ],
        ];

        Cache::set($cacheKey, $data, self::CACHE_TTL);
        return success('', $data);
    }
#[Get('/loginTrend')]
    public function loginTrend(Request $request): Response
    {
        $days = min(max((int)$request->get('days', 30), 7), 90);
        $cacheKey = 'dashboard:login_trend:' . $days;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $start = strtotime(date('Y-m-d 00:00:00', time() - ($days - 1) * 86400));
        $rows = Db::name('system_user_login_log')
            ->where('create_time', '>=', $start)
            ->field("FROM_UNIXTIME(create_time, '%Y-%m-%d') AS day, status, COUNT(*) AS num")
            ->group('day, status')
            ->select()
            ->toArray();

        $bucket = [];
        foreach ($rows as $row) {
            $bucket[$row['day']][(int)$row['status']] = (int)$row['num'];
        }

        // 补零，保证 X 轴连续
        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $dayTime = $start + $i * 86400;
            $list[] = [
                'date' => date('m-d', $dayTime),
                'success' => $bucket[date('Y-m-d', $dayTime)][1] ?? 0,
                'fail' => $bucket[date('Y-m-d', $dayTime)][2] ?? 0,
            ];
        }

        Cache::set($cacheKey, $list, 60);
        return success('', $list);
    }
#[Get('/newUsers')]
    public function newUsers(Request $request): Response
    {
        $limit = min(max((int)$request->get('limit', 8), 1), 20);
        $cacheKey = 'dashboard:new_users:' . $limit;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $list = User::field('id,username,nickname,account_type,status,create_time,last_login_time')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        Cache::set($cacheKey, $list, self::CACHE_TTL);
        return success('', $list);
    }
#[Get('/activities')]
    public function activities(Request $request): Response
    {
        $limit = min(max((int)$request->get('limit', 10), 1), 30);
        $cacheKey = 'dashboard:activities:' . $limit;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $list = SystemLog::field('id,username,business_name,method,ip,ip_location,create_time')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        Cache::set($cacheKey, $list, self::CACHE_TTL);
        return success('', $list);
    }
#[Get('/appStats')]
    public function appStats(): Response
    {
        $cacheKey = 'dashboard:app_stats';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $statusRows = Db::name('plugin_app')
            ->where('delete_time', null)
            ->field('status, COUNT(*) AS num')
            ->group('status')
            ->select()
            ->toArray();
        $counts = ['enabled' => 0, 'installed' => 0, 'disabled' => 0, 'error' => 0];
        $total = 0;
        foreach ($statusRows as $row) {
            $num = (int)$row['num'];
            $total += $num;
            match ((int)$row['status']) {
                1 => $counts['enabled'] += $num,
                2 => $counts['installed'] += $num,
                0 => $counts['disabled'] += $num,
                -1 => $counts['error'] += $num,
                default => null,
            };
        }

        $data = [
            'total' => $total,
            'counts' => $counts,
            'dev' => PluginApp::where('is_dev', 1)->count(),
        ];

        Cache::set($cacheKey, $data, 60);
        return success('', $data);
    }
#[Get('/storage')]
    public function storage(): Response
    {
        $cacheKey = 'dashboard:storage';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $typeLabels = [0 => '未分类', 1 => '图片', 2 => '文档', 3 => '视频', 4 => '音频', 5 => '压缩包', 9 => '其他'];
        $rows = Db::name('system_file')
            ->where('delete_time', null)
            ->where('type', 2)
            ->field('file_type, COUNT(*) AS num, SUM(size) AS size')
            ->group('file_type')
            ->select()
            ->toArray();

        $byType = [];
        $totalSize = 0;
        $totalCount = 0;
        foreach ($rows as $row) {
            $type = (int)$row['file_type'];
            $size = (int)$row['size'];
            $totalSize += $size;
            $totalCount += (int)$row['num'];
            $byType[] = [
                'type' => $type,
                'name' => $typeLabels[$type] ?? ('类型' . $type),
                'count' => (int)$row['num'],
                'size' => $size,
                'size_human' => self::humanSize($size),
            ];
        }
        // 图片→文档→视频→音频→其他，稳定展示顺序
        $order = array_flip(array_keys($typeLabels));
        usort($byType, fn($a, $b) => ($order[$a['type']] ?? 99) <=> ($order[$b['type']] ?? 99));

        $data = [
            'total' => $totalCount,
            'size' => $totalSize,
            'size_human' => self::humanSize($totalSize),
            'by_type' => $byType,
        ];

        Cache::set($cacheKey, $data, 60);
        return success('', $data);
    }
#[Get('/serverHealth')]
    public function serverHealth(): Response
    {
        $cacheKey = 'dashboard:server_health';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return success('', $cached);
        }

        $monitor = ServerMonitor::monitor();
        $disk = $monitor['disk'] ?? [];
        $data = [
            'overview' => $monitor['overview'] ?? [],
            'cpu' => [
                'cores' => $monitor['cpu']['cores'] ?? 0,
                'usagePercent' => $monitor['cpu']['usagePercent'] ?? null,
                'loadAvg' => $monitor['cpu']['loadAvg'] ?? [],
            ],
            'memory' => [
                'usedPercent' => $monitor['memory']['usedPercent'] ?? null,
                'totalHuman' => $monitor['memory']['totalHuman'] ?? '-',
                'usedHuman' => $monitor['memory']['usedHuman'] ?? '-',
            ],
            'disk' => [
                'usedPercent' => $disk['usedPercent'] ?? null,
                'totalHuman' => $disk['totalHuman'] ?? '-',
                'freeHuman' => $disk['freeHuman'] ?? '-',
                'items' => array_slice($disk['items'] ?? [], 0, 3),
            ],
            'php' => [
                'version' => $monitor['php']['version'] ?? '',
                'sapi' => $monitor['php']['sapi'] ?? '',
                'opcacheEnabled' => $monitor['php']['opcacheEnabled'] ?? null,
            ],
            'processes' => [
                'php' => $monitor['processes']['php']['total'] ?? 0,
                'webman' => $monitor['processes']['webman']['total'] ?? 0,
            ],
        ];

        Cache::set($cacheKey, $data, 10);
        return success('', $data);
    }

    /**
     * 时间锚点：今日 0 点、本周一 0 点、上周一 0 点
     * @return array{int,int,int,int,int}
     */
    private function timeBuckets(): array
    {
        $todayStart = (int)strtotime(date('Y-m-d 00:00:00'));
        $now = time();
        $weekday = (int)date('N', $now);
        $weekStart = $todayStart - ($weekday - 1) * 86400;
        $lastWeekStart = $weekStart - 7 * 86400;
        return [$todayStart, $now, $weekday, $weekStart, $lastWeekStart];
    }

    /** 字节数人性化 */
    private static function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return round($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }
}
