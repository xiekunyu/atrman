<?php

namespace app\model\system;

use app\basic\BaseModel;
use app\model\plugin\App as Plugin;

class Menu extends BaseModel
{
    protected $name = 'system_menu';

    public static $treeField = "id, id as value, name as label,pid, pid as parent_id, type";

    public function searchKeywordsAttr($model, $value)
    {
        if($value){
            $model->where('name|code|remark', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    /**
     * 关联父菜单（表列为 pid，无 parent_id）
     */
    public function parent()
    {
        return $this->belongsTo(Menu::class, 'pid');
    }

    /**
     * 关联子菜单
     */
    public function children()
    {
        return $this->hasMany(Menu::class, 'pid');
    }

    /**
     * 获取用户权限菜单
     *
     * 非超管且角色为空时必须返回空集：早期实现把「roleIds 为空」当成超管分支（不拼 role_menu 条件），
     * 于是不属于任何角色、或角色全部被禁用的账号能看到全部基座导航与所有插件入口。
     */
    public function getAuthMenus($roleIds=[], bool $isSuper = false)
    {
        $where=[
            ['status', '=', 1],
            ['type', 'in', [1, 2, 4]],
        ];
        // 插件启用中的名称：入口菜单只在读侧按它过滤，不改菜单 status（见 syncEntry 注释）
        $enabledApps = Plugin::where('status', 1)->column('name');
        $menu=self::distinct(true)->where($where)->where(function($query) use ($enabledApps){
            // 系统基座菜单（app_name 为空）全部可见；插件菜单只有"管理入口"(app:entry:%)且插件启用中才进基座导航，
            // 否则禁用/未安装的插件入口会留在侧栏，点进去被 PluginStatusCheck 拦成空白页
            if (empty($enabledApps)) {
                $query->where('app_name', '');
                return;
            }
            $query->where('app_name', '')->whereOr(function($sub) use ($enabledApps){
                $sub->where('code', 'like', 'app:entry:%')->where('app_name', 'in', $enabledApps);
            });
        });
        if($isSuper){
            $data = $menu->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        }elseif(empty($roleIds)){
            return [];
        }else{
            $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
            $data = $menu->where('id', 'in', array_unique($menuIds))
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        }
        return createArtdMenus($data);
    }

    /**
     * 获取指定插件的内部菜单树（供插件子应用侧栏使用）
     * @param string $appName 插件标识
     * @param array $roleIds 当前用户角色ID（超管忽略）
     * @param bool $isSuper 是否超级管理员
     */
    public function getPluginMenus(string $appName, array $roleIds = [], bool $isSuper = false): array
    {
        if ($appName === '') {
            return [];
        }
        $where = [
            ['status', '=', 1],
            ['type', 'in', [1, 2, 4]],
            ['app_name', '=', $appName],
            // 只取插件内部子菜单（页面挂在"管理入口"顶级菜单下），排除入口本身；
            // createArtdMenus 会把这些子菜单（父级入口不在结果集）自动提升为顶级。
            ['pid', '>', 0],
        ];
        $menu = self::distinct(true)->where($where);
        if (!$isSuper) {
            if (empty($roleIds)) {
                return [];
            }
            $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
            $menu = $menu->where('id', 'in', array_unique($menuIds));
        }
        $data = $menu->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return createArtdMenus($data);
    }

    /**
     * 获取角色按钮权限
     */
    public static function getRoleMenus($roleIds)
    {
        $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
        return Menu::distinct(true)
            ->where('type', 3)
            ->where('status', 1)
            ->where('id', 'in', array_unique($menuIds))
            ->column('slug');
    }
    
}
