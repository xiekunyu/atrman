<?php

namespace app\service;

use app\cache\UserInfoCache;
use app\model\system\Dept;
use app\model\system\DeptUser;
use app\model\system\RoleDept;

/**
 * 数据权限解析与应用。
 *
 * 语义：多角色取并集；结果结构 {all, self, dept_ids}
 * - all=true：全部数据，不过滤（含超管）。
 * - self=true：可见自己创建的行（created_by = uid）。
 * - dept_ids：可见部门集合（scope2/5 已展开子树，scope3 为所属部门本身）。
 * 部门→用户 owner 的反查按归属字段 created_by；表若声明 deptField 则直连。
 */
class DataScopeService
{
    /** 解析指定用户的数据可见范围 */
    public static function resolve(int $uid): array
    {
        $grant = ['all' => false, 'self' => false, 'dept_ids' => []];
        if ($uid <= 0) {
            return $grant;
        }
        if (isSuperAdmin($uid)) {
            $grant['all'] = true;
            return $grant;
        }

        $info = UserInfoCache::getUserInfo($uid);
        $roles = $info['roles'] ?? [];
        if (!$roles) {
            // 无角色兜底：仅本人
            $grant['self'] = true;
            return $grant;
        }

        $myDeptIds = array_map('intval', $info['dept_ids'] ?? []);
        $deptSet = [];
        $scope5RoleIds = [];

        foreach ($roles as $r) {
            switch ((int) ($r['data_scope'] ?? 0)) {
                case 1:
                    $grant['all'] = true;
                    break;
                case 2: // 本部门及下属：所属部门 + 子树
                    foreach ($myDeptIds as $d) {
                        foreach (self::deptSubtree($d) as $x) {
                            $deptSet[$x] = true;
                        }
                    }
                    break;
                case 3: // 本部门：仅所属部门
                    foreach ($myDeptIds as $d) {
                        $deptSet[$d] = true;
                    }
                    break;
                case 4: // 仅本人
                    $grant['self'] = true;
                    break;
                case 5: // 自定义：稍后按角色展开
                    $scope5RoleIds[] = (int) $r['id'];
                    break;
                default:
                    $grant['self'] = true; // 未识别档位按最严兜底
            }
        }

        if ($grant['all']) {
            return ['all' => true, 'self' => false, 'dept_ids' => []];
        }

        if ($scope5RoleIds) {
            foreach (RoleDept::whereIn('role_id', $scope5RoleIds)->column('dept_id') as $d) {
                foreach (self::deptSubtree((int) $d) as $x) {
                    $deptSet[$x] = true;
                }
            }
        }

        $grant['dept_ids'] = array_values(array_keys($deptSet));
        return $grant;
    }

    /** 把可见范围作为 where 条件施加到查询（Query/Model 均可） */
    public static function applyToQuery($query, int $uid, array $config): void
    {
        $grant = self::resolve($uid);
        if ($grant['all']) {
            return;
        }

        $userField = $config['userField'] ?? 'created_by';
        $deptField = $config['deptField'] ?? null;
        $conditions = [];

        if ($grant['self']) {
            $conditions[] = [$userField, '=', $uid];
        }
        if (!empty($grant['dept_ids'])) {
            if ($deptField) {
                $conditions[] = [$deptField, 'in', $grant['dept_ids']];
            } else {
                $userIds = DeptUser::whereIn('dept_id', $grant['dept_ids'])->column('user_id');
                $userIds = array_values(array_unique(array_map('intval', $userIds)));
                $conditions[] = [$userField, 'in', $userIds ?: [0]];
            }
        }

        if (!$conditions) {
            // 既非全部、又无 self/dept 授权：结果置空
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($conditions) {
            foreach ($conditions as $i => $c) {
                if ($i === 0) {
                    $q->where($c[0], $c[1], $c[2]);
                } else {
                    $q->whereOr($c[0], $c[1], $c[2]);
                }
            }
        });
    }

    /** 单条可见判定（read/update/delete 防绕过） */
    public static function canSee(int $uid, array $row, array $config): bool
    {
        $grant = self::resolve($uid);
        if ($grant['all']) {
            return true;
        }

        $userField = $config['userField'] ?? 'created_by';
        $deptField = $config['deptField'] ?? null;

        if ($grant['self'] && (int) ($row[$userField] ?? 0) === $uid) {
            return true;
        }

        if (!empty($grant['dept_ids'])) {
            if ($deptField) {
                return in_array((int) ($row[$deptField] ?? 0), $grant['dept_ids'], true);
            }
            $ownerId = (int) ($row[$userField] ?? 0);
            if ($ownerId <= 0) {
                return false;
            }
            $ownerDepts = array_map('intval', DeptUser::where('user_id', $ownerId)->column('dept_id'));
            return !empty(array_intersect($ownerDepts, $grant['dept_ids']));
        }

        return false;
    }

    /** 部门子树 id（含自身） */
    private static function deptSubtree(int $deptId): array
    {
        $rows = Dept::getAllChild($deptId);
        return array_map('intval', array_column($rows, 'id'));
    }
}
