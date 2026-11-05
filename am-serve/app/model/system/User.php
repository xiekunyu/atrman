<?php

namespace app\model\system;

use app\basic\BaseModel;
use app\cache\UserAuthCache;
use app\model\system\UserRole;
use app\model\system\Role;
use app\model\system\Dept;

class User extends BaseModel
{
    protected $name = 'system_user';

    /**
     * 序列化黑名单：凭证材料（密码哈希/盐）任何接口都不得经模型序列化输出，
     * delete_time 沿用 BaseModel 的隐藏口径。模型级兜底，覆盖所有遗漏 hidden() 的出口。
     */
    protected $hidden = ['delete_time', 'password', 'salt'];

    /** 账号身份类别：管理员（后台运营人员，可进基座成员管理列表） */
    public const ACCOUNT_ADMIN = 1;

    /** 账号身份类别：成员（普通人员，会员中心展示，不进基座后台人员列表） */
    public const ACCOUNT_MEMBER = 2;

    public function searchKeywordsAttr($model, $value)
    {
        if($value){
            $model->where('username|nickname|mobile|email', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, UserRole::class, 'role_id', 'user_id');
    }

    public function dept()
    {
        return $this->hasOne(Dept::class, 'id', 'dept_id');
    }

    public function avatar()
    {
        return $this->hasOne(File::class, 'id', 'avatar_id');
    }

    // 获取组织架构列表
    public static function getStructure(array $data): array
    {
        $list=[];
        $user_ids = $data['user_ids'] ?? [];
        if(!empty($user_ids)){
           $userList=User::field('id,nickname as name')->where([['id','in',$user_ids],['status','=',1]])->select()->toArray();
            if(!empty($userList)){
                foreach($userList as $item){
                    $item['stype']='user';
                    $item['key']='user_'.$item['id'];
                    $list[]=$item;
                }
            }
        }
        $dept_ids = $data['dept_ids'] ?? [];
        if(!empty($dept_ids)){
            $deptList=Dept::field('id,name')->where([['id','in',$dept_ids],['status','=',1]])->select()->toArray();
            if(!empty($deptList)){
                foreach($deptList as $item){
                    $item['stype']='dept';
                    $item['key']='dept_'.$item['id'];
                    $list[]=$item;
                }
            }
        }
        $tags_ids = $data['tags_ids'] ?? [];
        if(!empty($tags_ids)){
            $tagsList=Tags::field('id,name')->where([['id','in',$tags_ids],['status','=',1]])->select()->toArray();
            if(!empty($tagsList)){
                foreach($tagsList as $item){
                    $item['stype']='tags';
                    $item['key']='tags_'.$item['id'];
                    $list[]=$item;
                }
            }
        }
        return $list;
    }

    /** 获取当前用户所有权限信息。 */
    public static function getAuthContext(): array
    {
        $userInfo = getCurrentInfo();
        $userId = (int)($userInfo['id'] ?? 0);
        // 必须查缓存：JWT 载荷不含 buttons，从载荷读会让 is_admin 恒为假，
        // 于是「指定可见」的插件对被授权的管理员也永远隐藏（见 AppController 的 hasPermission 门禁）。
        // 超管判定只认 UserAuthCache 的 ['*'] 哨兵，不再在此处重复硬编码 id=1（口径见 AuthCheck）
        $buttons = (array) UserAuthCache::getUserAuth($userId);

        return [
            'user_id' => $userId,
            'is_admin' => in_array('*', $buttons, true),
            'dept_ids' => normalizeIds(Dept::getUserDeptParentIds($userId))
        ];
    }

    // 根据权限筛选出所有的用户和部门
    public static function getAuthUserDeptIds(array $authContext): array
    {
        $user_ids = normalizeIds($authContext['user_ids'] ?? []);
        $dept_ids = normalizeIds($authContext['dept_ids'] ?? []);
        $tags_ids = normalizeIds($authContext['tags_ids'] ?? []);

        if($tags_ids){
            // 查询所有标签的用户和部门并合并
            $tagsList=Tags::where('id','in',$tags_ids)->select()->toArray();
            if(!empty($tagsList)){
                foreach($tagsList as $item){
                    $user_ids = array_merge($user_ids, normalizeIds($item['user_ids'] ?? []));
                    $dept_ids = array_merge($dept_ids, normalizeIds($item['dept_ids'] ?? []));
                }
            }
        }

        $dept_ids = normalizeIds($dept_ids);
        if($dept_ids){
            // 查询所有部门的子部门并合并
            $deptList=Dept::where('id','in',$dept_ids)->select()->toArray();
            if(!empty($deptList)){
                foreach($deptList as $item){
                    $dept_ids = array_merge($dept_ids, normalizeIds($item['childs'] ?? []));
                }
            }
        }

        return [
            'user_ids' => normalizeIds($user_ids),
            'dept_ids' => normalizeIds($dept_ids)
        ];
    }

    // 判断用户是否有权限
    public static function hasPermission(array $auth,array $authContext): bool
    {
        $permission=self::getAuthUserDeptIds($auth);
        if($authContext['is_admin']){
            return true;
        }
        // 如果三个数据都是空数组，说明所有人都有权限
        if(empty($permission['user_ids']) && empty($permission['dept_ids'])){
            return true;
        }
        if(in_array($authContext['user_id'],$permission['user_ids'])){
            return true;
        }
        // 判断是否有交集
        if(!empty(array_intersect($permission['dept_ids'],$authContext['dept_ids']))){
            return true;
        }
        return false;
    }

    
}
