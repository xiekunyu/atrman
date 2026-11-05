<?php
// +----------------------------------------------------------------------
// | atrman企业快速开发框架
// +----------------------------------------------------------------------
// | Author: raingad <raingad@foxmail.com>
// +----------------------------------------------------------------------
namespace app\basic;

use support\think\Model;
use think\model\concern\SoftDelete;

/**
 * ThinkORM 模型基类
 */
class BaseModel extends Model
{
    use SoftDelete;

    /**
     * 删除时间字段
     * @var string
     */
    protected $deleteTime = 'delete_time';

    /**
     * 创建时间字段
     * @var string
     */
    protected $createTime = 'create_time';

    /**
     * 更新时间字段
     * @var string
     */
    protected $updateTime = 'update_time';

    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = ['delete_time'];

    protected $autoWriteTimestamp = true;

    /**
     * 只读字段
     * @var array
     */
    protected $readonly = ['created_by', 'create_time'];

    /**
     * 获取表名
     * @return string
     */
    public function getTableName(): string
    {
        return $this->getTable();
    }

    /**
     * 获取主键名
     * @return string
     */
    public function getPrimaryKeyName(): string
    {
        return $this->getPk();
    }

    /**
     * 添加时间范围搜索
     * @param $query
     * @param $value
     */
    public function searchCreateTimeAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereBetween('create_time', $value);
        } else {
            $query->where('create_time', '=', $value);
        }
    }

    /**
     * 更新时间范围搜索
     * @param mixed $query
     * @param mixed $value
     */
    public function searchUpdateTimeAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereBetween('update_time', $value);
        } else {
            $query->where('update_time', '=', $value);
        }
    }


    /**
     * 新增前事件
     * @param Model $model
     * @return void
     */
    public static function onBeforeInsert($model): void
    {
        // 用 currentUserId() 而非直接解引用 JWT 载荷：队列/CLI 上下文没有请求态，
        // 且载荷缺 id 时直接 $info['id'] 会告警并写入 null
        $uid = currentUserId();
        if ($uid > 0) {
            $model->setAttr('created_by', $uid);
        }
    }

    /**
     * 写入前事件
     * @param Model $model
     * @return void
     */
    public static function onBeforeWrite($model): void
    {
        $uid = currentUserId();
        if ($uid > 0) {
            $model->setAttr('updated_by', $uid);
        }
    }
}
