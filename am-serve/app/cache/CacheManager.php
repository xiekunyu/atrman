<?php

namespace app\cache;

use app\basic\RegistryGuard;
use app\exception\ApiException;
use support\think\Cache;

class CacheManager
{
    /** 空结果缓存时长（秒）：见 remember 内的说明 */
    private const EMPTY_TTL = 60;

    /** 根配置里存放失效组的保留键（不参与缓存域枚举） */
    private const GROUPS_KEY = 'groups';

    /**
     * 插件在运行期登记的缓存域：[owner(插件名) => [域编码 => 域定义]]。
     * 进程级静态：由 PluginManager::loadPluginCacheDomains 在每个 worker 启动时对已启用插件重放，
     * 禁用/卸载由 forgetByOwner 对称清理（跨 worker 由下次重启收敛）。
     * @var array<string, array<string, array>>
     */
    private static array $pluginDomains = [];

    public static function config(string $name): array
    {
        return config('cache_manage.' . $name, []);
    }

    /**
     * 登记某插件声明的缓存域（幂等：同 owner 重复登记整体覆盖，进程重启后由 boot 重放收敛）。
     * 保留键 groups 只属于基座根配置，插件文件里出现也不登记为域。
     * @param array<string, array> $domains 域编码 => 域定义
     */
    public static function registerPluginDomains(string $owner, array $domains): void
    {
        RegistryGuard::assertWritable(self::class, 'registerPluginDomains');
        $owner = trim($owner);
        if ($owner === '' || !$domains) {
            return;
        }
        $normalized = [];
        foreach ($domains as $code => $def) {
            $code = trim((string)$code);
            if ($code === '' || $code === self::GROUPS_KEY || !is_array($def)) {
                continue;
            }
            $normalized[$code] = $def;
        }
        self::$pluginDomains[$owner] = $normalized;
    }

    /** 按 owner 清理某插件登记的全部缓存域（禁用/卸载时由 PluginManager 调用）。 */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        unset(self::$pluginDomains[$owner]);
    }

    /**
     * 缓存监控/清理视图的完整域集合：基座根配置 cache_manage + 各已启用插件登记域。
     * 冲突（同域编码）以先到者为准（基座优先，其次登记顺序），后到者跳过。
     * @return array<string, array>
     */
    public static function allDomains(): array
    {
        $domains = (array)config('cache_manage', []);
        unset($domains[self::GROUPS_KEY]); // 失效组不是缓存域，别让它出现在监控/清理列表里
        foreach (self::$pluginDomains as $ownerDomains) {
            foreach ($ownerDomains as $code => $def) {
                if (isset($domains[$code])) {
                    continue; // 域编码冲突：保留基座/先登记方
                }
                $domains[$code] = $def;
            }
        }
        return $domains;
    }

    public static function remember(string $key, int $ttl, callable $callback, array|string $tags = []): mixed
    {
        $data = Cache::get($key);
        if ($data !== null) {
            return $data;
        }

        $data = $callback();
        // 空结果只短存：清缓存与并发回填之间读到的"还没有数据"是瞬间态（新建账号、刚授权的角色），
        // 按业务 TTL 正缓存会让新账号最长几小时菜单空白、按钮全 403 且不自愈
        if ($data === [] || $data === '' || $data === null) {
            $ttl = min($ttl, self::EMPTY_TTL);
        }
        $tags = is_array($tags) ? array_filter($tags) : ($tags ? [$tags] : []);
        if ($tags) {
            Cache::tag($tags)->set($key, $data, $ttl);
        } else {
            Cache::set($key, $data, $ttl);
        }

        return $data;
    }

    public static function clearKey(string $key): bool
    {
        return Cache::delete($key);
    }

    public static function clearTag(string $tag): bool
    {
        Cache::tag($tag)->clear();
        return true;
    }

    public static function domains(): array
    {
        return self::namespaces(false);
    }

    public static function monitor(): array
    {
        $driver = self::defaultDriver();
        $storeConfig = self::storeConfig($driver);
        $isRedis = self::isRedisDriver($driver, $storeConfig);
        $handler = null;
        $error = '';
        $latency = null;
        $info = [];

        if ($isRedis) {
            try {
                $start = microtime(true);
                $handler = Cache::store()->handler();
                $pong = method_exists($handler, 'ping') ? $handler->ping() : true;
                $latency = round((microtime(true) - $start) * 1000, 2);
                if ($pong === false) {
                    $error = 'Redis ping 失败';
                }
                $info = self::redisInfo($handler);
            } catch (\Throwable $e) {
                $handler = null;
                $error = $e->getMessage();
            }
        }

        $namespaces = self::namespaces(true, $handler);
        $keyCount = self::redisDbSize($handler);
        $hits = (int)($info['keyspace_hits'] ?? 0);
        $misses = (int)($info['keyspace_misses'] ?? 0);
        $hitRate = $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 2) : 0;
        $status = !$isRedis ? 'warning' : ($error ? 'error' : 'connected');

        return [
            'overview' => [
                'driver' => $driver,
                'isRedis' => $isRedis,
                'status' => $status,
                'statusText' => $status === 'connected' ? '已连接' : ($status === 'warning' ? '非Redis驱动' : '连接异常'),
                'engine' => $isRedis ? 'Redis' : ucfirst($driver),
                'keyCount' => $keyCount,
                'memoryHuman' => (string)($info['used_memory_human'] ?? '0B'),
                'opsPerSecond' => (int)($info['instantaneous_ops_per_sec'] ?? 0),
                'connectedClients' => (int)($info['connected_clients'] ?? 0),
                'hitRate' => $hitRate,
                'updatedAt' => date('Y-m-d H:i:s'),
            ],
            'connection' => [
                'host' => $storeConfig['host'] ?? '',
                'port' => (int)($storeConfig['port'] ?? 6379),
                'database' => (int)($storeConfig['select'] ?? $storeConfig['database'] ?? 0),
                'prefix' => (string)($storeConfig['prefix'] ?? ''),
                'timeout' => (($storeConfig['pool']['wait_timeout'] ?? $storeConfig['timeout'] ?? 3) * 1000) . 'ms',
                'latencyMs' => $latency,
                'memoryHuman' => (string)($info['used_memory_human'] ?? '0B'),
                'opsPerSecond' => (int)($info['instantaneous_ops_per_sec'] ?? 0),
                'message' => $error ?: ($isRedis ? 'Redis 连接正常' : '当前业务缓存未使用 Redis'),
            ],
            'stages' => self::stages($driver, $isRedis, $error, count($namespaces)),
            'namespaces' => $namespaces,
        ];
    }

    public static function namespaces(bool $withStats = true, mixed $handler = null): array
    {
        $domains = self::allDomains();
        $result = [];
        $driver = self::defaultDriver();
        $isRedis = self::isRedisDriver($driver, self::storeConfig($driver));

        foreach ($domains as $code => $domain) {
            $patterns = self::patterns($domain);
            $tag = $domain['tag'] ?? '';
            $keyCount = null;
            if ($withStats) {
                $tagCount = $tag ? count(self::tagItems($tag)) : 0;
                $scanCount = $isRedis && $handler ? self::countPatterns($handler, $patterns) : null;
                $keyCount = $scanCount ?? $tagCount;
            }

            $result[] = [
                'code' => $code,
                'name' => $domain['name'] ?? $code,
                'description' => $domain['description'] ?? '',
                'tag' => $tag,
                'key' => $domain['key'] ?? null,
                'prefix' => $domain['prefix'] ?? null,
                'expire' => (int)($domain['expire'] ?? 0),
                'expireText' => self::formatTtl((int)($domain['expire'] ?? 0)),
                'keyCount' => $keyCount,
                'clearable' => true,
                'patterns' => $patterns,
            ];
        }

        return $result;
    }

    public static function clearNamespace(?string $code = null): array
    {
        $code = trim((string)$code);
        if ($code === '') {
            return self::clearAllNamespaces();
        }

        $domains = self::allDomains();
        if (!isset($domains[$code])) {
            return [
                'mode' => 'namespace',
                'code' => $code,
                'success' => false,
                'deleted' => 0,
                'message' => '缓存命名空间不存在',
            ];
        }

        return array_merge(['mode' => 'namespace'], self::clearDomain($code, $domains[$code]));
    }

    public static function clearAllNamespaces(): array
    {
        $items = [];
        $total = 0;
        foreach (self::allDomains() as $code => $domain) {
            $item = self::clearDomain((string)$code, $domain);
            $items[] = $item;
            $total += (int)($item['deleted'] ?? 0);
        }

        // 只清共享存储的话，本进程的控制器白名单/插件状态备忘还是旧的，
        // 「全部清理」要连它们一起失效，才与按钮文案相符
        $targets = [];
        foreach (self::groups() as $group) {
            $targets = array_merge($targets, self::flushTargets((array)$group));
        }

        return [
            'mode' => 'all',
            'totalDeleted' => $total,
            'safeMode' => true,
            'items' => $items,
            'flushed' => self::flushInProcess($targets),
        ];
    }

    /** 失效组声明（根配置 cache_manage.groups） */
    public static function groups(): array
    {
        return (array)config('cache_manage.' . self::GROUPS_KEY, []);
    }

    /**
     * 按失效组清理：先走单域清理路径清共享存储，再 flush 进程内备忘。
     *
     * flush 只作用于当前 worker，跨 worker 靠各自的短 TTL 自然收敛 —— 这一点必须写进
     * 暴露给运维的文案，否则会被理解成「点一下全集群生效」。
     *
     * @return array{group:string,domains:array,flushed:string[]}
     */
    public static function clearGroup(string $name): array
    {
        $group = self::groups()[$name] ?? null;
        if ($group === null) {
            throw new ApiException('缓存失效组不存在：' . $name);
        }

        $domains = [];
        foreach ((array)($group['domains'] ?? []) as $code) {
            $domains[] = self::clearNamespace((string)$code);
        }

        return ['group' => $name, 'domains' => $domains, 'flushed' => self::flushInProcess(self::flushTargets($group))];
    }

    public static function clearKeys(string $prefix, int|array $ids): bool
    {
        foreach ((array) $ids as $id) {
            if ($id !== '' && $id !== null) {
                Cache::delete($prefix . $id);
            }
        }
        return true;
    }

    public static function clearTags(string $prefix, int|array $ids): bool
    {
        foreach ((array) $ids as $id) {
            if ($id !== '' && $id !== null) {
                self::clearTag($prefix . $id);
            }
        }
        return true;
    }

    /**
     * 展开失效组的进程内备忘清理项。groups 由开发者手写配置，写法不对要报得清楚，
     * 不能让 destructuring 或 fatal 去顶替配置说明。
     * @return array<int, array{0:string, 1:string}>
     */
    private static function flushTargets(array $group): array
    {
        $targets = [];
        foreach ((array)($group['flush'] ?? []) as $index => $entry) {
            if (!is_array($entry) || array_keys($entry) !== [0, 1] || !is_string($entry[0]) || !is_string($entry[1])) {
                throw new ApiException('缓存失效组 flush[' . $index . '] 必须写成 [类名, 静态方法名]');
            }
            [$class, $method] = $entry;
            if (!method_exists($class, $method)) {
                throw new ApiException('缓存失效组要清理的方法不存在：' . $class . '::' . $method);
            }
            $targets[] = [$class, $method];
        }
        return $targets;
    }

    /** 逐项失效进程内备忘，同一项一次调用只清一次 */
    private static function flushInProcess(array $targets): array
    {
        $flushed = [];
        foreach ($targets as [$class, $method]) {
            $label = $class . '::' . $method;
            if (in_array($label, $flushed, true)) {
                continue;
            }
            $class::$method();
            $flushed[] = $label;
        }
        return $flushed;
    }

    private static function clearDomain(string $code, array $domain): array
    {
        $deleted = 0;
        $success = true;
        $message = '清理成功';

        try {
            if (!empty($domain['tag'])) {
                $deleted += count(self::tagItems((string)$domain['tag']));
                self::clearTag((string)$domain['tag']);
            }
            if (!empty($domain['key'])) {
                $deleted += Cache::has((string)$domain['key']) ? 1 : 0;
                Cache::delete((string)$domain['key']);
            }

            $driver = self::defaultDriver();
            if (self::isRedisDriver($driver, self::storeConfig($driver))) {
                $handler = Cache::store()->handler();
                $deleted += self::deletePatterns($handler, self::patterns($domain));
            }
        } catch (\Throwable $e) {
            $success = false;
            $message = $e->getMessage();
        }

        return [
            'code' => $code,
            'name' => $domain['name'] ?? $code,
            'success' => $success,
            'deleted' => $deleted,
            'message' => $message,
        ];
    }

    private static function defaultDriver(): string
    {
        return (string)config('think-cache.default', 'file');
    }

    private static function storeConfig(string $driver): array
    {
        return (array)config('think-cache.stores.' . $driver, []);
    }

    private static function isRedisDriver(string $driver, array $storeConfig): bool
    {
        return strtolower((string)($storeConfig['type'] ?? $driver)) === 'redis';
    }

    private static function redisInfo(mixed $handler): array
    {
        if (!$handler || !method_exists($handler, 'info')) {
            return [];
        }
        $info = $handler->info();
        if (is_array($info)) {
            return $info;
        }
        if (!is_string($info)) {
            return [];
        }
        $data = [];
        foreach (preg_split('/\r?\n/', $info) as $line) {
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $data[$key] = $value;
        }
        return $data;
    }

    private static function redisDbSize(mixed $handler): int
    {
        if (!$handler || !method_exists($handler, 'dbSize')) {
            return 0;
        }
        try {
            return (int)$handler->dbSize();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function patterns(array $domain): array
    {
        $patterns = [];
        if (!empty($domain['key'])) {
            $patterns[] = (string)$domain['key'];
        }
        if (!empty($domain['prefix'])) {
            $patterns[] = rtrim((string)$domain['prefix'], '*') . '*';
        }
        return array_values(array_unique($patterns));
    }

    private static function realPattern(string $pattern): string
    {
        $prefix = (string)config('think-cache.stores.redis.prefix', '');
        return $prefix . $pattern;
    }

    private static function countPatterns(mixed $handler, array $patterns): int
    {
        $count = 0;
        foreach ($patterns as $pattern) {
            $count += count(self::scanKeys($handler, self::realPattern($pattern), 10000));
        }
        return $count;
    }

    private static function deletePatterns(mixed $handler, array $patterns): int
    {
        $deleted = 0;
        foreach ($patterns as $pattern) {
            $keys = self::scanKeys($handler, self::realPattern($pattern), 10000);
            foreach (array_chunk($keys, 500) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                if (method_exists($handler, 'unlink')) {
                    $result = $handler->unlink($chunk);
                } else {
                    $result = $handler->del($chunk);
                }
                $deleted += is_int($result) ? $result : count($chunk);
            }
        }
        return $deleted;
    }

    private static function scanKeys(mixed $handler, string $pattern, int $limit): array
    {
        if (!$handler || !method_exists($handler, 'scan')) {
            return [];
        }
        $keys = [];
        $iterator = null;
        do {
            $batch = $handler->scan($iterator, $pattern, 500);
            if (is_array($batch)) {
                foreach ($batch as $key) {
                    $keys[] = $key;
                    if (count($keys) >= $limit) {
                        return array_values(array_unique($keys));
                    }
                }
            }
        } while ($iterator > 0);

        return array_values(array_unique($keys));
    }

    private static function tagItems(string $tag): array
    {
        try {
            return Cache::store()->getTagItems($tag);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function formatTtl(int $seconds): string
    {
        if ($seconds <= 0) {
            return '永久';
        }
        if ($seconds % 86400 === 0) {
            return ($seconds / 86400) . '天';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . '小时';
        }
        if ($seconds % 60 === 0) {
            return ($seconds / 60) . '分钟';
        }
        return $seconds . '秒';
    }

    private static function stages(string $driver, bool $isRedis, string $error, int $namespaceCount): array
    {
        return [
            ['title' => '配置加载', 'status' => 'success', 'message' => '缓存配置已读取'],
            ['title' => '缓存驱动', 'status' => $isRedis ? 'success' : 'warning', 'message' => '当前驱动：' . $driver],
            ['title' => '连接检测', 'status' => $error ? 'error' : 'success', 'message' => $error ?: ($isRedis ? 'Redis 连接正常' : '非 Redis 驱动无需连接检测')],
            ['title' => '业务命名空间', 'status' => 'success', 'message' => '已加载 ' . $namespaceCount . ' 个业务缓存空间'],
        ];
    }

}
