<?php

namespace app\controller;

use app\service\system\HealthProbeService;
use app\service\system\ObservabilityService;
use support\annotation\route\Get;
use support\Log;
use support\Redis;
use support\Request;
use support\Response;
use Webman\RedisQueue\Consumer;

/**
 * Prometheus 指标端点（免 JWT，端点内自验密钥）。
 *
 * 安全口径：
 * - METRICS_TOKEN 未配置时整体 404（fail-closed，不暴露采集面）；
 * - token 经 ?token= 或 Authorization: Bearer 提供，hash_equals 计时安全比对，错误返 401；
 * - AuthCheck 公开前缀只负责跳过 JWT，密钥校验只在本端点内完成。
 *
 * 输出 Prometheus text exposition（手写，无新依赖）。所有取数 try 兜底，
 * 探针故障给 -1 并继续输出其余指标，端点自身永不 500。
 */
class MetricsController
{
    /** 与 AuthCheck 前缀白名单互为兜底的免登声明 */
    protected array $noNeedLogin = ['index'];

    /**
     * redis-queue 协议键，来源 vendor/workerman/redis-queue/src/Client.php 常量：
     * waiting 是按队列 list（键名无分隔符），delayed 是全局 zset，failed 是全局共享 list
     */
    private const WAITING_PREFIX = '{redis-queue}-waiting';
    private const DELAYED_KEY = '{redis-queue}-delayed';
    private const FAILED_KEY = '{redis-queue}-failed';

    #[Get('/metrics')]
    public function index(Request $request): Response
    {
        $configuredToken = (string)env('METRICS_TOKEN', '');
        if ($configuredToken === '') {
            return response('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $token = (string)$request->get('token', '');
        if ($token === '') {
            $authorization = (string)$request->header('Authorization', '');
            if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $matches)) {
                $token = $matches[1];
            }
        }
        if (!hash_equals($configuredToken, $token)) {
            return response('Unauthorized', 401, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($this->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /** 组装全部指标文本（每行一个指标族样本，末尾补换行） */
    private function render(): string
    {
        $lines = [];

        // 1) 当前 worker 内存（抓取请求落在哪个 worker 就报哪个，标签带 worker_id/pid 区分）。
        // 本版 Workerman 的 id 是 Worker 实例属性（非旧版静态属性），经 Webman\App::worker() 取实例
        $workerId = \Webman\App::worker()?->id ?? -1;
        $lines[] = '# HELP atrman_worker_memory_bytes Current worker real memory usage in bytes.';
        $lines[] = '# TYPE atrman_worker_memory_bytes gauge';
        $lines[] = sprintf(
            'atrman_worker_memory_bytes{worker_id="%s",pid="%s"} %d',
            $this->label((string)$workerId),
            $this->label((string)getmypid()),
            memory_get_usage(true)
        );

        // 2) 依赖探活往返延迟（秒），探针失败为 -1（HealthController 同口径）
        $lines[] = '# HELP atrman_probe_latency_seconds Dependency probe round-trip latency in seconds (-1 on failure).';
        $lines[] = '# TYPE atrman_probe_latency_seconds gauge';
        $checks = HealthProbeService::checks();
        $lines[] = 'atrman_probe_latency_seconds{target="redis"} ' . $checks['redis']['latency'];
        $lines[] = 'atrman_probe_latency_seconds{target="mysql"} ' . $checks['db']['latency'];

        // 3) 队列积压与 job 失败/重试计数
        foreach ($this->queueLines() as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 队列指标行。Redis 故障时 gauge 降级为 -1、计数器行省略（counter 不允许回退为负值），
     * redis 不可用本身已由 atrman_probe_latency_seconds=-1 体现。
     */
    private function queueLines(): array
    {
        $queues = $this->discoverQueues();

        $lines = [
            '# HELP atrman_queue_waiting Messages waiting in each redis-queue list.',
            '# TYPE atrman_queue_waiting gauge',
            '# HELP atrman_queue_delayed Messages in the global delayed zset.',
            '# TYPE atrman_queue_delayed gauge',
            '# HELP atrman_queue_failed Messages in the global failed (dead letter) list.',
            '# TYPE atrman_queue_failed gauge',
            '# HELP atrman_job_total Total consumer failure callbacks by result (fail=DLQ, retry=requeued).',
            '# TYPE atrman_job_total counter',
        ];

        try {
            $conn = Redis::connection('queue');
            foreach ($queues as $queue) {
                $lines[] = sprintf(
                    'atrman_queue_waiting{queue="%s"} %d',
                    $this->label($queue),
                    (int)$conn->lLen(self::WAITING_PREFIX . $queue)
                );
            }
            $lines[] = 'atrman_queue_delayed ' . (int)$conn->zCard(self::DELAYED_KEY);
            // failed 是 list（lLen），与 waiting 同型；不可误用 zCard
            $lines[] = 'atrman_queue_failed ' . (int)$conn->lLen(self::FAILED_KEY);

            foreach ($queues as $queue) {
                foreach (['fail', 'retry'] as $result) {
                    $count = (int)$conn->get(ObservabilityService::JOB_COUNTER_PREFIX . $queue . ':' . $result);
                    $lines[] = sprintf(
                        'atrman_job_total{queue="%s",result="%s"} %d',
                        $this->label($queue),
                        $result,
                        $count
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[metrics] 队列指标读取失败（gauge 降级为 -1，计数器本次省略）：' . $e->getMessage());
            foreach ($queues as $queue) {
                $lines[] = sprintf('atrman_queue_waiting{queue="%s"} -1', $this->label($queue));
            }
            $lines[] = 'atrman_queue_delayed -1';
            $lines[] = 'atrman_queue_failed -1';
        }

        return $lines;
    }

    /**
     * 反射 app/queue/redis 下所有 Webman\RedisQueue\Consumer 实现类的公共 $queue 属性。
     * 扫描目录与框架 Consumer 进程（webman/redis-queue）同口径，队列名不硬编码。
     *
     * @return list<string>
     */
    private function discoverQueues(): array
    {
        $dir = app_path() . '/queue/redis';
        if (!is_dir($dir)) {
            return [];
        }
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        } catch (\Throwable) {
            return [];
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $queues = [];
        foreach ($iterator as $file) {
            if (is_dir($file) || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($base));
            $class = str_replace('/', '\\', ltrim(substr($relative, 0, -4), '/'));
            if (!class_exists($class) || !is_a($class, Consumer::class, true)) {
                continue;
            }
            $queue = (string)((new \ReflectionClass($class))->getDefaultProperties()['queue'] ?? '');
            if ($queue !== '') {
                $queues[] = $queue;
            }
        }
        return array_values(array_unique($queues));
    }

    /** Prometheus 标签值转义（反斜杠、双引号、换行） */
    private function label(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
