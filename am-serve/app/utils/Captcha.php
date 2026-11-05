<?php
// +----------------------------------------------------------------------
// | 引用于 saiadmin [ saiadmin快速开发框架 ]
// +----------------------------------------------------------------------
// |  Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace app\utils;

use support\think\Cache;
use Webman\Captcha\CaptchaBuilder;
use Webman\Captcha\PhraseBuilder;
use app\exception\ApiException;
use app\utils\Hashids;

/**
 * 验证码工具类
 */
class Captcha
{
    /**
     * 图形验证码
     * @return array
     */
    public static function imageCaptcha(): array
    {
        $builder = new PhraseBuilder(4, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ123456789');
        $captcha = new CaptchaBuilder(null, $builder);
        $captcha->setBackgroundColor(242, 243, 245);
        $captcha->build(120, 36);
        $key = Hashids::getUuid();
        $code = strtolower($captcha->getPhrase());
        Cache::set($key, $code,300);
        $img_content = $captcha->get();
        return [
            'result' => 1,
            'uuid' => $key,
            'image' => 'data:image/png;base64,' . base64_encode($img_content)
        ];
    }

    /**
     * 数字验证码
     * @param string $key
     * @param int $length
     * @return array
     */
    public static function numberCaptcha(string $key, int $length = 4): array
    {
        $code = str_pad(rand(0, 999999), $length, '0', STR_PAD_LEFT);
        Cache::set($key, $code,300);
        return [
            'result' => 1,
            'uuid' => $key,
            'code' => $code,
        ];
    }

    /**
     * 验证码验证
     * @param string $uuid
     * @param string|int $captcha
     * @return bool
     */
    public static function checkCaptcha(string $uuid, string|int $captcha): bool
    {
        try {
            $code = Cache::get($uuid);
            Cache::delete($uuid);
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }
        if (strtolower($captcha) !== $code) {
            return false;
        }
        return true;
    }
}
