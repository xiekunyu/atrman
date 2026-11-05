<?php

namespace app\service\upload\adapter;

/**
 * 七牛云适配器（基座扩展）
 *
 * 继承 Tinywan\Storage\Adapter\QiniuAdapter，上传行为完全不变；补齐统一删除契约。
 * 注：删除走 Qiniu BucketManager（官方删除通道）——原实现经 UploadManager 调 delete
 * 属无效调用（该方法不存在），此处改为官方删除通道。
 */
class QiniuAdapter extends \Tinywan\Storage\Adapter\QiniuAdapter
{
    /**
     * 删除对象
     */
    public function deleteObject(string $bucket, string $path): void
    {
        $auth = new \Qiniu\Auth($this->config['accessKey'], $this->config['secretKey']);
        [, $error] = (new \Qiniu\Storage\BucketManager($auth))->delete($bucket, $path);
        if ($error !== null) {
            throw new \RuntimeException('七牛云删除失败：' . (string)$error);
        }
    }
}
