<?php
// app/basic/AdapterRegistry.php

namespace app\basic;

use support\Log;

/**
 * 存储适配器注册中心
 * 负责管理所有存储适配器的注册和获取
 *
 * 说明：驱动的"显示名 + 凭证表单字段"不在此处维护，统一走配置管理体系
 * （am_system_config 配置组，app_name='storage'，code=驱动类型），
 * 后台存储弹窗的下拉与动态表单即由配置组驱动；插件新增驱动时同样声明配置组即可，
 * 无需第二套元数据来源。
 */
class AdapterRegistry
{
    /**
     * 适配器注册表
     * key: 存储类型名称
     * value: 适配器类名
     * 注：oss/qiniu/cos/s3 指向基座扩展子类（继承 Tinywan 适配器 + 补齐统一删除契约），
     *     上传行为与 Tinywan 原类完全一致
     */
    private static array $adapters = [
        'local' => \Tinywan\Storage\Adapter\LocalAdapter::class,
        'oss' => \app\service\upload\adapter\OssAdapter::class,
        'qiniu' => \app\service\upload\adapter\QiniuAdapter::class,
        'cos' => \app\service\upload\adapter\CosAdapter::class,
        's3' => \app\service\upload\adapter\S3Adapter::class,
    ];

    /**
     * 配置映射表
     * key: 配置键名
     * value: 配置闭包或数组
     */
    private static array $configResolvers = [
        'local' => null,  // 使用默认
        'oss' => null,   // 使用默认
        'qiniu' => null,  // 使用默认
        'cos' => null,    // 使用默认
        's3' => null,     // 使用默认
    ];

    /**
     * 驱动归属表
     * key: 存储类型名称
     * value: 归属插件名（内置/基座注册为空串），供卸载/禁用对称清理
     */
    private static array $owners = [];

    /**
     * 注册一个新存储适配器
     * @param string $type 存储类型名称
     * @param string $adapterClass 适配器类名
     * @param callable|null $configResolver 配置解析器
     * @param string $owner 归属插件名（插件注册必传，卸载/禁用对称清理用）
     */
    public static function register(
        string $type,
        string $adapterClass,
        ?callable $configResolver = null,
        string $owner = ''
    ): void {
        RegistryGuard::assertWritable(self::class, 'register');
        // 内置/基座注册的驱动（owner 为空串）是全站共用的事实来源：插件不得改判同名键。
        // 否则后启用的插件可以把 'oss'/'local' 换成自己的实现，接管其它应用与基座的上传落盘。
        // 命中只忽略并留日志：抛异常会让整个插件 boot 失败，代价与风险不成比例。
        if ($owner !== '' && isset(self::$adapters[$type]) && self::owner($type) === '') {
            Log::warning("[AdapterRegistry] 驱动 {$type} 由基座注册，插件「{$owner}」的改判注册已忽略");
            return;
        }

        self::$adapters[$type] = $adapterClass;

        if ($configResolver !== null) {
            self::$configResolvers[$type] = $configResolver;
        }
        self::$owners[$type] = $owner;
    }

    /**
     * 获取适配器类名
     * @param string $type
     * @return string|null
     */
    public static function getAdapterClass(string $type): ?string
    {
        return self::$adapters[$type] ?? null;
    }


    /**
     * 获取配置解析器
     * @param string $type
     * @return callable|null
     */
    public static function getConfigResolver(string $type): ?callable
    {
        return self::$configResolvers[$type] ?? null;
    }

    /**
     * 检查是否已注册
     * @param string $type
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$adapters[$type]);
    }

    /**
     * 获取所有已注册的适配器
     * @return array
     */
    public static function all(): array
    {
        return self::$adapters;
    }

    /**
     * 获取某驱动的归属插件名（内置/基座注册返回空串）
     */
    public static function owner(string $type): string
    {
        return self::$owners[$type] ?? '';
    }

    /**
     * 批量注册适配器（插件使用）
     * @param array $adapters 结构：type => ['adapter' => 类名, 'resolver' => callable|null, 'owner' => 插件名]
     */
    public static function batchRegister(array $adapters): void
    {
        foreach ($adapters as $type => $config) {
            self::register(
                $type,
                $config['adapter'],
                $config['resolver'] ?? null,
                $config['owner'] ?? ''
            );
        }
    }

    /**
     * 按 owner 清理某插件注册的全部驱动（禁用/卸载时由 PluginManager 调用）
     *
     * 注：内置类型 owner 为空串，不会被清理；只清插件注册的自定义驱动。
     */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        $toRemove = array_keys(array_filter(self::$owners, fn($o) => $o === $owner));
        foreach ($toRemove as $type) {
            unset(self::$adapters[$type], self::$configResolvers[$type], self::$owners[$type]);
        }
    }
}
