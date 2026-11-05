<?php
namespace app\middleware;

use app\model\plugin\App as Plugin;
use app\service\StoreService;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class PluginStatusCheck implements MiddlewareInterface
{
    /** 进程级状态缓存 TTL（秒）：降低每请求查库开销；跨 worker 最迟 5s 感知状态变化，
     *  基座变更插件状态时会主动 flush 当前进程缓存 */
    protected const CACHE_TTL = 5;

    /** @var array<string, array{expire:int, plugin:Plugin}> 仅缓存"启用中"正向结果；未启用不缓存，保证启用后立即放行 */
    protected static array $statusCache = [];

    /** 清理插件状态缓存（cache_manage.groups.runtime 的 flush 项，插件状态变更与系统还原后触发） */
    public static function flush(?string $pluginName = null): void
    {
        if ($pluginName === null) {
            self::$statusCache = [];
            return;
        }
        unset(self::$statusCache[$pluginName]);
    }

    public function process(Request $request, callable $handler): Response
    {
        $pluginName = $this->pluginNameFromPath($request->path());
        if ($pluginName === '') {
            return $handler($request);
        }

        $now = time();
        $cached = self::$statusCache[$pluginName] ?? null;
        if ($cached && $cached['expire'] > $now) {
            $plugin = $cached['plugin'];
        } else {
            $plugin = Plugin::where('name', $pluginName)->where('status', 1)->findOrEmpty();
            if ($plugin->isEmpty()) {
                unset(self::$statusCache[$pluginName]);
                return response('Not Found', 404);
            }
            self::$statusCache[$pluginName] = ['expire' => $now + self::CACHE_TTL, 'plugin' => $plugin];
        }

        // 付费应用授权心跳：每周一次，用户打开应用时触发，异步队列执行不阻塞请求
        // markHeartbeatDispatched 使用原子更新，并发请求只有第一个能成功标记并投递
        if (StoreService::shouldHeartbeat($plugin) && StoreService::markHeartbeatDispatched($plugin)) {
            StoreService::dispatchHeartbeat((int)$plugin->id);
        }

        return $handler($request);
    }

    protected function pluginNameFromPath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if (preg_match('#^/plugin/(app|widget|store)(/|$)#', $path)) {
            return '';
        }
        if (preg_match('#^/(?:plugin|app)/(am_[a-z][a-z0-9_]*)(?:/|$)#', $path, $matches)) {
            return $matches[1];
        }
        return '';
    }
}