<?php

namespace app\interface;

/**
 * 存储插件基础接口
 * 所有存储插件必须实现此接口
 */
interface StoragePluginInterface
{
    /**
     * 获取适配器类名
     * @return string
     */
    public static function getAdapterClass(): string;


    /**
     * 注册插件到全局注册中心
     */
    public static function register(): void;

    /**
     * 获取配置（由框架自动调用）
     * @param array $globalConfig
     * @return array
     */
    public static function getConfig(array $globalConfig): array;
}