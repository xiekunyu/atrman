<?php

namespace app\service\upload\adapter;

/**
 * 阿里云 OSS 适配器（基座扩展）
 *
 * 继承 Tinywan\Storage\Adapter\OssAdapter，上传行为完全不变；
 * 仅补齐统一删除契约 deleteObject(string $bucket, string $path): void，
 * 供 UploadService::delete 去 switch 硬编码后按契约调用。
 */
class OssAdapter extends \Tinywan\Storage\Adapter\OssAdapter
{
    /**
     * 删除对象
     */
    public function deleteObject(string $bucket, string $path): void
    {
        $this->getInstance()->deleteObject($bucket, $path);
    }
}
