<?php

namespace app\service\upload;

use app\basic\AdapterRegistry;
use app\exception\ApiException;
use app\interface\UploadConfigResolverInterface;

/**
 * 上传配置默认解析器（接管点 'upload.config' 的 priority=0 默认实现）
 *
 * 逻辑逐行复刻原 UploadService::resolveConfig 的 switch，行为与原基座完全一致；
 * 插件可通过 ServiceRegistry::bind('upload.config', 自定义类, 优先级>0, 插件名) 覆盖，
 * 覆盖实现解析失败时自动回退本类（见 ServiceRegistry::make）。
 */
class DefaultUploadConfigResolver implements UploadConfigResolverInterface
{
    /**
     * 默认配置解析（原 UploadService::resolveConfig 原样迁移，不改行为）
     */
    public function resolve(string $type, array $config): array
    {
        $config['adapter'] = AdapterRegistry::getAdapterClass($type);

        switch ($type) {
            case 'local':
                // 本地
                $config['root'] = public_path() . ($config['root'] ?? '/storage/');
                $config['dirname'] = date('Ymd');
                $config['algo'] = $config['algo'] ?? 'md5';
                // 本地存储与站点同域：domain 为空时回退为当前访问域名，免去硬编码或安装期装配
                if (empty($config['domain'])) {
                    $config['domain'] = assetDomain();
                }
                break;
            case 'oss':
                // 阿里云
                $config['algo'] = $config['algo'] ?? 'md5';
                $config['dirname'] = trim($config['dirname'], '/') . '/' . date('Ymd');
                break;
            case 'qiniu':
            case 'cos':
            case 's3':
                $config['dirname'] = trim($config['dirname'], '/') . '/' . date('Ymd');
                // s3 亚马逊
                $config['s3_use_path_style_endpoint'] = filter_var($config['s3_use_path_style_endpoint'], FILTER_VALIDATE_BOOLEAN);
                break;
            default:
                throw new ApiException('该上传模式不存在');
        }
        return $config;
    }
}
