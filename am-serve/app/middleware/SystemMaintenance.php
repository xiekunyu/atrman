<?php

namespace app\middleware;

use app\service\plugin\CoreUpgradeService;
use app\service\system\MaintenanceService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 维护模式中间件（主程序升级 / 系统恢复共用）
 *
 * 升级任务（CoreUpgradeService）或系统恢复队列任务（MaintenanceService）置标记期间，
 * 对访客与业务请求展示维护页，避免半升级/半恢复状态下产生错误数据；任务收尾后解除。
 * 两个标记来源分键存储，本中间件任一命中即拦截。
 *
 * 放行规则（白名单，刻意保守最小）：
 * - 静态资源与 SPA 外壳（带扩展名的文件、/index.html、站点根路径）——升级提示页要能加载；
 * - /pub/* 公共与登录接口、/plugin/webman/push/* 推送通道（进度实时推送依赖）；
 * - /plugin/store/system-update*（升级检查与进度轮询，触发者要能看到进度）；
 * - /api/v1/* 应用市场对外 API（市场与实例同站部署时，升级任务自己要在维护期里换票下载）；
 * - 其余 GET /sys/* 只读后台请求放行（后台需能正常展示进度），
 *   所有写操作（POST/PUT/DELETE）与 /app/*、/plugin/{am_*}/* 业务请求一律拦截。
 */
class SystemMaintenance implements MiddlewareInterface
{
    /** 视为静态资源/页面外壳的扩展名 */
    private const ASSET_EXT = [
        'html', 'htm', 'js', 'mjs', 'css', 'json', 'map', 'txt', 'xml', 'ico',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'woff', 'woff2',
        'ttf', 'otf', 'eot', 'mp3', 'mp4', 'webmanifest',
    ];

    public function process(Request $request, callable $handler): Response
    {
        if (!CoreUpgradeService::isMaintaining() && !MaintenanceService::isActive()) {
            return $handler($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        if ($this->allowed($path, $method)) {
            return $handler($request);
        }

        return $this->refuse($request, $path);
    }

    /** 是否属于维护期间仍放行的请求。 */
    protected function allowed(string $path, string $method): bool
    {
        // 站点根与 SPA 外壳
        if ($path === '/' || $path === '/index.html') {
            return true;
        }
        // 健康探针：维护模式是人为停服而非进程死亡，liveness 必须保持 200，
        // 否则编排系统会在升级/恢复窗口反复重启实例
        if ($path === '/health' || $path === '/health/ready') {
            return true;
        }
        // 静态资源
        $ext = strtolower((string)pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::ASSET_EXT, true)) {
            return true;
        }
        // 公共接口（含登录、站点信息）与推送通道
        foreach (['/pub/', '/plugin/webman/push/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        // 升级检查与进度查询
        if (str_starts_with($path, '/plugin/store/system-update')) {
            return true;
        }
        // 应用市场对外 API：自身就是市场站的实例，升级任务要在维护窗口内换票与拉包，
        // 不放行会导致“一进维护就下载不了自己的升级包”而每次升级必失败；
        // 这些端点不读本站业务数据，且自带 Bearer / 一次性 ticket 鉴权，放行不额外增加风险
        if (str_starts_with($path, '/api/v1/')) {
            return true;
        }
        // 后台只读请求放行，写操作拦截
        if ($method === 'GET' && str_starts_with($path, '/sys/')) {
            return true;
        }
        return false;
    }

    /** 维护中响应：接口返回 503 JSON，浏览器返回维护提示页。 */
    protected function refuse(Request $request, string $path): Response
    {
        // 升级标记优先：它带 hold/目标版本等恢复标记没有的语义；未升级时取通用维护标记
        $upgrade = CoreUpgradeService::maintenanceInfo();
        $restore = MaintenanceService::info();
        $info = $upgrade ?: $restore;
        $isUpgrade = $upgrade !== [];
        $since = (string)($info['since_text'] ?? '');
        $target = (string)($info['to'] ?? '');
        // 钉住状态：回滚不彻底的半升级站点，不能只说“请稍后再试”，要明说需人工处理
        $hold = $isUpgrade && !empty($upgrade['hold']);
        $retry = $hold ? max(300, (int)config('store.maintenance_retry', 120))
            : max(30, (int)config('store.maintenance_retry', 120));

        $isApi = str_starts_with($path, '/app/')
            || str_starts_with($path, '/plugin/')
            || str_contains((string)$request->header('accept', ''), 'application/json')
            || $request->header('x-requested-with') === 'XMLHttpRequest';

        if ($isApi) {
            // 不用 json() 封装：它把第二个参数当 json_encode 选项而非状态码，必须显式构返 503
            if ($hold) {
                $msg = '系统升级未完成，站点暂停访问，请联系管理员处理';
            } elseif ($isUpgrade) {
                $msg = '系统升级中，请稍后再试' . ($target !== '' ? '（目标版本 ' . $target . '）' : '');
            } else {
                $msg = '系统维护中（' . (string)($restore['label'] ?? '恢复任务') . '），请稍后再试';
            }
            $body = (string)json_encode([
                'code' => 503,
                'msg' => $msg,
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return response($body, 503, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Retry-After' => (string)$retry,
            ]);
        }

        $note = $since !== '' ? '开始时间：' . htmlspecialchars($since, ENT_QUOTES) . '<br>' : '';
        if ($hold) {
            $headline = '系统升级已中断';
            $lead = '本次升级未能完成且部分文件无法自动还原，站点保持暂停访问，需管理员人工处理。';
        } elseif ($isUpgrade) {
            $headline = '系统升级中';
            $lead = '系统正在进行版本升级，站点暂停访问，请稍后再试。';
        } else {
            $headline = '系统维护中';
            $lead = '系统正在执行' . htmlspecialchars((string)($restore['label'] ?? '维护任务'), ENT_QUOTES)
                . '，站点暂停访问，请稍后再试。';
        }
        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="refresh" content="' . $retry . '">'
            . '<title>系统升级中</title><style>body{margin:0;height:100vh;display:flex;align-items:center;'
            . 'justify-content:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f7fa'
            . ';color:#303133}.box{text-align:center;padding:40px 48px;background:#fff;border-radius:12px;'
            . 'box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{margin:0 0 12px;font-size:22px}p{margin:6px 0;'
            . 'font-size:14px;color:#606266}.spin{width:34px;height:34px;margin:0 auto 18px;border:3px solid #dcdfe6;'
            . 'border-top-color:#409eff;border-radius:50%;animation:s 1s linear infinite}@keyframes s{to{transform:rotate(360deg)}}'
            . '</style></head><body><div class="box"><div class="spin"></div><h1>' . $headline . '</h1>'
            . '<p>' . $lead . '</p>'
            . '<p>' . $note . '本页将自动重试。</p></div></body></html>';

        return response($html, 503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Retry-After' => (string)$retry,
        ]);
    }
}
