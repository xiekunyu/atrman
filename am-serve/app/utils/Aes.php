<?php

namespace app\utils;

/**
 * 遗留 AES-128-ECB 工具：ECB 无 IV、相同明文块产出相同密文块，不具语义安全。
 * 当前全仓无调用方，保留仅为历史数据兼容；新链路请直接用 openssl AES-256-GCM，不要接回这里。
 */
class Aes
{
    /**
     * AES ECB 加密
     * @param $data string 加密数据
     * @param $key string 秘钥
     * @return string
     */
    public static function encrypt($data, $key)
    {
        $data = openssl_encrypt($data, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        return base64_encode($data);
    }

    /**
     *  AES ECB 解密
     * @param $data string 解密数据
     * @param $key string 秘钥
     * @return false|string
     */
    public static function decrypt($data, $key)
    {
        $encrypted = base64_decode($data);
        return openssl_decrypt($encrypted, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
    }

}