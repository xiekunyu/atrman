<?php

namespace app\interface;

/**
 * 链路追踪接管点（key: trace）——OpenTelemetry 占位契约。
 *
 * 当前默认实现 app\basic\NoopTrace 为空操作；后续接入 OTel 时只需在插件 boot() 内
 * ServiceRegistry::bind(TraceInterface::KEY, OtelTrace::class, 优先级>0, 插件名)
 * 整体接管，业务代码先按本接口写，切换时零改造（见 ServiceRegistry 接管模式）。
 */
interface TraceInterface extends ServiceProviderInterface
{
    /** 接管点标识 */
    public const KEY = 'trace';

    /**
     * 开启一个 span。
     *
     * @param string $name span 名称（建议 控制器::动作 / 服务::方法）
     * @param array $attributes 初始属性
     * @return string span 句柄，传给 endSpan/setError；无追踪实现时返回占位 id
     */
    public function startSpan(string $name, array $attributes = []): string;

    /**
     * 结束 span。
     *
     * @param string $spanId startSpan 返回的句柄
     * @param array $attributes 结束时附加属性
     */
    public function endSpan(string $spanId, array $attributes = []): void;

    /** 标记 span 为异常状态（不结束 span，endSpan 仍需正常调用） */
    public function setError(string $spanId, \Throwable $error): void;
}
