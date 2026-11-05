<?php

namespace app\cache;

use app\model\system\Dict;
use app\utils\Tree;

class DictCache
{
    public static function getDictAll(): array
    {
        $cache = CacheManager::config('dict');
        return CacheManager::remember($cache['key'] ?? 'atrman:dict:all', $cache['expire'] ?? 31536000, fn() => self::setDictAll(), $cache['tag'] ?? 'atrman:dict');
    }

    public static function getDict(string $code): array
    {
        $data = self::getDictAll();
        return $data[$code] ?? [];
    }

    public static function setDictAll(): array
    {
        $list = Dict::where(['status' => 1])->select()->toArray();
        $tree = Tree::createTreeByList($list);
        $data = [];
        foreach ($tree as $item) {
            $data[$item['code']] = $item['children'] ?? [];
        }
        return $data;
    }

    public static function clear(): bool
    {
        $cache = CacheManager::config('dict');
        CacheManager::clearKey($cache['key'] ?? 'atrman:dict:all');
        return CacheManager::clearTag($cache['tag'] ?? 'atrman:dict');
    }
}
