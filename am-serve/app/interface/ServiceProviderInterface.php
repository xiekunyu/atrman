<?php

namespace app\interface;

/**
 * 服务接管点通用契约（标记接口 + 接管点标识常量）
 *
 * 每个可被插件接管的基座服务定义一个契约接口（继承本接口并覆写 KEY 常量），
 * 其默认实现类登记在 config/plugin_extend.php 的 providers 列表，由
 * ServiceRegistry 懒加载：KEY => 默认实现（priority=0）。
 *
 * 插件在 boot() 内以 KEY 为标识接管：
 * - ServiceRegistry::bind(KEY, 自定义实现类, 优先级>0, 插件名)  整体覆盖；
 * - ServiceRegistry::extend(KEY, 装饰器, 优先级, 插件名)        包装增强；
 * 未被接管或接管实现解析异常时，make() 自动回退默认实现（等同基座现状行为）。
 *
 * 设计说明：接管点标识用接口常量而非 key()/default() 静态方法——接口上的静态
 * 方法是抽象的、不可直接调用，且实现类需重复声明；常量单一真源、可被
 * `Interface::KEY` 直接引用。
 */
interface ServiceProviderInterface
{
    /**
     * 接管点全局唯一标识（小写点分格式，如 'upload.config'）
     * 子接口必须覆写为实际标识
     */
    public const KEY = '';
}
