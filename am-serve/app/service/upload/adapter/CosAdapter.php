<?php

namespace app\service\upload\adapter;

/**
 * 腾讯云 COS 适配器（基座扩展）
 *
 * 继承 Tinywan\Storage\Adapter\CosAdapter，上传行为完全不变；
 * 仅补齐统一删除契约 deleteObject(string $bucket, string $path): void。
 */
class CosAdapter extends \Tinywan\Storage\Adapter\CosAdapter
{
    /**
     * 删除对象
     */
    public function deleteObject(string $bucket, string $path): void
    {
        $this->getInstance()->deleteObject([
            'Bucket' => $bucket,
            'Key' => $path,
        ]);
    }
}
