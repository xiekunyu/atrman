<?php

namespace app\model\notify;

use app\basic\BaseModel;
use app\model\system\Config;

class SmsProvider extends BaseModel
{
    protected $name = 'system_sms_provider';

    protected $json = ['config'];

    // 时间字段存整数时间戳
    protected $autoWriteTimestamp = 'timestamp';

    // 搜索器：关键词搜索
    public function searchKeywordsAttr($query, $value)
    {
        if ($value) {
            $query->where('name', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：名称搜索
    public function searchNameAttr($query, $value)
    {
        if ($value) {
            $query->where('name', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：状态筛选
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    // 关联配置信息（参照 Storage::configInfo）
    public function configInfo()
    {
        return $this->hasOne(Config::class, 'id', 'type');
    }

    /**
     * 获取默认供应商
     */
    public static function getDefaultProvider(): ?static
    {
        return static::where('is_default', 1)
            ->where('status', 1)
            ->find();
    }

    /**
     * 根据编码获取供应商
     */
    public static function getByCode(string $code): ?static
    {
        return static::where('code', $code)
            ->where('status', 1)
            ->find();
    }

    /**
     * 获取供应商配置（含关联配置信息）
     */
    public function getSmsProviderConfig(int $id): array
    {
        $info = $this->with(['configInfo'])->findOrEmpty($id);
        if (!$info) {
            return [];
        }
        $data = $info->toArray();
        $config = (array)($data['config'] ?? []);
        $config['code'] = $data['code'] ?? '';
        return $config;
    }
}
