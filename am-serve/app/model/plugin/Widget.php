<?php

namespace app\model\plugin;

use app\basic\BaseModel;
use app\cache\DictCache;
use app\exception\ApiException;

class Widget extends BaseModel
{
    protected $name = 'plugin_widget';

    /** 按应用标识筛选。 */
    public function searchAppNameAttr(mixed $model, mixed $value): void
    {
        if ($value) {
            $model->where('app_name', $value);
        }
    }


    /** 按组件标识筛选。 */
    public function searchNameAttr(mixed $model, mixed $value): void
    {
        if ($value) {
            $model->where('name', $value);
        }
    }

    /** 按组件类型筛选。 */
    public function searchCateAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('cate', (int)$value);
        }
    }

    /** 按 widget/page 类型筛选。 */
    public function searchTypeAttr(mixed $model, mixed $value): void
    {
        if ($value) {
            $model->where('type', $value);
        }
    }

    /** 按状态筛选。 */
    public function searchStatusAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('status', (int)$value);
        }
    }

    /** 获取小组件尺寸选项。 */
    public static function sizeOptions(): array
    {
        return array_values(array_filter(array_map(
            fn($item) => (string)($item['value'] ?? ''),
            DictCache::getDict('widget_size')
        ), fn($value) => $value !== ''));
    }

    /** 校验同插件下小组件标识唯一。 */
    public static function assertUniqueName(App $plugin, string $name): void
    {
        $exists = self::where('app_name', $plugin->name)->where('name', $name)->findOrEmpty();
        if (!$exists->isEmpty()) {
            throw new ApiException('小组件标识不能重复');
        }
    }

    /** 补充接口展示字段。 */
    public static function formatRow(array $widget): array
    {
        $widget['plugin_name'] = App::where('name', $widget['app_name'])->value('title');
        $widget['status'] = (int)($widget['status'] ?? 0);
        $widget['cate'] = (int)($widget['cate'] ?? 1);
        return $widget;
    }
}