<?php

namespace app\model\plugin;

use app\basic\BaseModel;
use app\exception\ApiException;

class App extends BaseModel
{
    protected $name = 'plugin_app';

    protected $json = ['auth', 'config', 'manifest', 'composer_state'];

    /** 按关键词搜索插件。 */
    public function searchKeywordsAttr(mixed $model, mixed $value): void
    {
        if ($value) {
            $model->where('name|title|description|author', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    /** 按状态筛选插件。 */
    public function searchStatusAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('status', $value);
        }
    }

    /** 按插件标识筛选插件。 */
    public function searchNameAttr(mixed $model, mixed $value): void
    {
        if ($value) {
            $model->where('name', $value);
        }
    }

    /** 按自定义插件标记筛选。 */
    public function searchIsCustomAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('is_custom', (int)$value);
        }
    }

    /** 按核心应用标记筛选。 */
    public function searchIsCoreAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('is_core', (int)$value);
        }
    }

    /** 按开发模式标记筛选。 */
    public function searchIsDevAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('is_dev', (int)$value);
        }
    }

    /** 按类型筛选。 */
    public function searchTypeAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('type', (int)$value);
        }
    }

    /** 按来源筛选。 */
    public function searchSourceAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('source', (int)$value);
        }
    }

    /** 按适用范围筛选。 */
    public function searchScopeAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('scope', (int)$value);
        }
    }

    /** 按使用设备筛选。 */
    public function searchPlatformAttr(mixed $model, mixed $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('platform', (int)$value);
        }
    }

    /** 按插件标识或 ID 获取插件。 */
    public static function requireByAppIdOrId(mixed $appId, mixed $pluginId = ''): self
    {
        $plugin = null;
        if ($pluginId !== '' && $pluginId !== null) {
            $plugin = self::findOrEmpty($pluginId);
        } elseif ($appId !== '' && $appId !== null) {
            $plugin = self::where('name', \normalizePluginName((string)$appId))->findOrEmpty();
        }
        if (!$plugin || $plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        return $plugin;
    }

    /** 按插件标识或 ID 获取开发模式插件。 */
    public static function requireDevByAppIdOrId(mixed $appId, mixed $pluginId = '', string $message = '仅开发模式插件允许管理插件'): self
    {
        $plugin = self::requireByAppIdOrId($appId, $pluginId);
        if ((int)($plugin->is_dev ?? 0) !== 1) {
            throw new ApiException($message);
        }
        return $plugin;
    }
}
