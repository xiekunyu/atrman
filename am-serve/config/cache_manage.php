<?php

use app\cache\ReflectionCache;
use app\middleware\PluginStatusCheck;

return [
    'user' => [
        'name' => '用户信息缓存',
        'description' => '当前用户基础信息、角色、部门、头像等',
        'prefix' => 'atrman:user:info_',
        'expire' => 60 * 60 * 4,
        'tag' => 'atrman:user',
        'role' => 'atrman:user:role_',
        'dept' => 'atrman:user:dept_',
    ],
    'auth' => [
        'name' => '用户权限缓存',
        'description' => '用户按钮权限 slug',
        'prefix' => 'atrman:auth:user_',
        'expire' => 60 * 60 * 2,
        'tag' => 'atrman:auth',
        'role' => 'atrman:auth:role_',
    ],
    'menu' => [
        'name' => '用户菜单缓存',
        'description' => '用户可访问菜单树',
        'prefix' => 'atrman:menu:user_',
        'expire' => 60 * 60 * 24 * 7,
        'tag' => 'atrman:menu',
    ],
    'dict' => [
        'name' => '字典缓存',
        'description' => '公共字典数据',
        'key' => 'atrman:dict:all',
        'expire' => 60 * 60 * 24 * 365,
        'tag' => 'atrman:dict',
    ],
    'config' => [
        'name' => '系统配置缓存',
        'description' => '系统配置组数据',
        'prefix' => 'atrman:config:group_',
        'expire' => 60 * 60 * 24 * 365,
        'tag' => 'atrman:config',
    ],
    'oauth' => [
        'name' => 'OAuth缓存',
        'description' => 'SSO免登凭证等OAuth临时数据',
        'prefix' => 'atrman:oauth:sso_',
        'expire' => 300,
        'tag' => 'atrman:oauth',
    ],
    'ticket' => [
        'name' => '免登票据缓存',
        'description' => '基座为登录主体签发的一次性免登ticket（jti防重放）',
        'prefix' => 'atrman:ticket:jti_',
        'expire' => 60,
        'tag' => 'atrman:ticket',
    ],

    /**
     * 失效组：一次业务动作要连带失效的域集合 + 进程内 memo。
     *
     * domains 走与后台「缓存监控/一键清理」相同的单域清理路径；
     * flush 是 [类, 静态方法] 列表，只对当前 worker 生效（跨 worker 靠各自短 TTL 收敛）。
     */
    'groups' => [
        'runtime' => [
            'name' => '运行期缓存',
            'description' => '插件状态变更后需失效的配置/菜单/权限/用户信息，以及本进程的反射与插件状态备忘',
            'domains' => ['config', 'menu', 'auth', 'user'],
            'flush' => [
                [ReflectionCache::class, 'clear'],
                [PluginStatusCheck::class, 'flush'],
            ],
        ],
    ],
];
