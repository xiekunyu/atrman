<?php

namespace app\basic\concern;

/**
 * 数据权限标记 Trait。
 *
 * 模型 `use DataScopeTrait` 即声明"参与数据权限"，未声明的模型一律不过滤（保护平台级接口）。
 * 可通过覆盖 $dataScope 指定归属字段：
 * - userField：所有者字段（默认 created_by）
 * - deptField：部门字段（默认 null，经 DeptUser 由 owner 反查部门；表若有 dept_id 可声明直连）
 */
trait DataScopeTrait
{
    protected array $dataScope = ['userField' => 'created_by', 'deptField' => null];

    /** 数据权限归属字段配置 */
    public function dataScopeConfig(): array
    {
        return $this->dataScope;
    }
}
