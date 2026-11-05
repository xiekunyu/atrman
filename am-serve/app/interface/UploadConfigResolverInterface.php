<?php

namespace app\interface;

/**
 * 上传配置解析接管点（key: upload.config）
 *
 * 契约：把存储类型 + 后台存储配置解析为适配器构造所需的最终配置
 * （补 adapter/dirname/algo 等字段），未知类型抛 ApiException。
 *
 * 默认实现 DefaultUploadConfigResolver 逐行复刻基座原 UploadService::resolveConfig，
 * 插件可在 boot() 内 ServiceRegistry::bind('upload.config', 自定义类, 优先级, 插件名) 覆盖。
 */
interface UploadConfigResolverInterface extends ServiceProviderInterface
{
    /** 接管点标识 */
    public const KEY = 'upload.config';

    /**
     * 解析存储配置
     *
     * @param string $type 存储类型（local/oss/qiniu/cos/s3 或插件注册的自定义类型）
     * @param array $config 后台存储配置原始数据
     * @return array 适配器可直接使用的完整配置（含 adapter 键）
     */
    public function resolve(string $type, array $config): array;
}
