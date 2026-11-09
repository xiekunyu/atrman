<?php
namespace app\utils;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Tuupola\Base62;

class Hashids
{
    /**
     * 主体前缀：人（统一主体，管理员与会员共用）。
     * 语义为“person principal”，不再仅表示“后台系统用户”。
     */
    public const TYPE_PERSON = 'sys';

    /**
     * 主体前缀：群聊（IM 中的非人主体）。
     */
    public const TYPE_GROUP = 'grp';

    /**
     * 主体前缀：机器人（预留）。
     */
    public const TYPE_BOT = 'bot';

    /**
     * 主体前缀：应用身份（预留）。
     */
    public const TYPE_APP = 'app';

    /**
     * 生成优化版分布式 UUID（Base62 编码 UUID7，22 字符，小写）
     */
    public static function getUuid(): string
    {
        // 初始化 UUID7 工厂（确保时间戳有序）
        $factory = new UuidFactory();
        Uuid::setFactory($factory);
        
        // 生成 UUID7 并转为字节流（16 字节，节省内存）
        $uuid7 = Uuid::uuid7();
        $bytes = $uuid7->getBytes();
        
        // Base62 编码+转小写，避免大小写问题
        $base62 = new Base62();
        $shortUuid = $base62->encode($bytes);
        return strtolower($shortUuid);
    }

    /**
     * 可选：将短 UUID 转回原始 UUID（用于兼容场景）
     */
    public static function decodeShortUuid(string $shortUuid): string
    {
        $base62 = new Base62();
        $bytes = $base62->decode($shortUuid);
        return Uuid::fromBytes($bytes)->toString();
    }

    /**
     * 获取source id
     */
    public static function getSid($name='source'): string
    {
        return $name . '_'.self::getUuid();
    }

    /**
     * 根据 sid 前缀解析主体类型（'sys'/'grp'/'bot'/'app' 等）。
     * 全系统统一用此方法判断“是人还是群/机器人”，禁止各处手写字符串匹配。
     * 无法识别（无前缀或格式异常）时返回空字符串。
     */
    public static function typeOf(?string $sid): string
    {
        if ($sid === null || $sid === '') {
            return '';
        }
        $pos = strpos($sid, '_');
        return $pos === false ? '' : substr($sid, 0, $pos);
    }

    /**
     * 判断 sid 是否为“人”主体。
     */
    public static function isPerson(?string $sid): bool
    {
        return self::typeOf($sid) === self::TYPE_PERSON;
    }
}