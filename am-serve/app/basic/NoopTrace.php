<?php

namespace app\basic;

use app\interface\TraceInterface;

/**
 * 链路追踪接管点 'trace' 的 priority=0 默认实现：全空操作。
 *
 * 登记在 config/plugin_extend.php 的 providers，未安装 OTel 接管插件时
 * ServiceRegistry::make(TraceInterface::KEY) 落到本类：调用零副作用、零外部依赖。
 */
class NoopTrace implements TraceInterface
{
    public function startSpan(string $name, array $attributes = []): string
    {
        // 占位句柄：保证业务侧拿到的始终是非空字符串，无需判空
        return 'noop';
    }

    public function endSpan(string $spanId, array $attributes = []): void
    {
    }

    public function setError(string $spanId, \Throwable $error): void
    {
    }
}
