<?php

namespace app\service\system;

use support\Redis;
use support\think\Db;

/**
 * 依赖探活与延迟测量：HealthController 就绪探针与 MetricsController 指标端点共用，
 * 保证两处对 Redis/DB 的健康判定同一口径。全部探测 try 兜底，探针自身永不抛异常。
 */
class HealthProbeService
{
    /**
     * 逐项探活并测量往返延迟（秒，4 位小数）。
     *
     * @return array{redis: array{status:string, latency:float}, db: array{status:string, latency:float}}
     *         status=up|down；探测失败时 latency=-1
     */
    public static function checks(): array
    {
        return [
            'redis' => self::probe(static fn () => Redis::ping()),
            'db' => self::probe(static fn () => Db::query('SELECT 1')),
        ];
    }

    /**
     * @return array{status:string, latency:float}
     */
    private static function probe(callable $fn): array
    {
        $startedAt = microtime(true);
        try {
            $fn();
            return ['status' => 'up', 'latency' => round(microtime(true) - $startedAt, 4)];
        } catch (\Throwable) {
            return ['status' => 'down', 'latency' => -1.0];
        }
    }
}
