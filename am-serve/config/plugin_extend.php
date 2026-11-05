<?php
/**
 * 插件扩展架构统一特性开关与接管点声明
 *
 * 约定：新增能力默认"仅在被注册时生效"，
 * 此文件是灰度与回滚的唯一开关入口。关闭某项后对应机制短路为基座默认行为。
 */

return [
    // 事件总线 EventRegistry：聚合插件 config/event.php、标准业务事件 emit（关闭后 emit 为空操作）
    'event_enabled' => true,

    // 服务接管 ServiceRegistry：关闭后 make() 短路返回默认实现，忽略一切 bind/extend
    'service_enabled' => true,

    // 插件中间件织入（阶段三）：安全敏感，默认关闭，仅受信插件场景下灰度开启
    'middleware_enabled' => false,

    // 插件定时任务调度进程（阶段三）：无注册任务时进程空转，开销可忽略
    'task_enabled' => true,

    // 插件信封队列 plugin_job（阶段四）：无投递时无任何消费
    'queue_enabled' => true,

    // 推送频道鉴权扩展（阶段四）：无匹配鉴权器时保持原放行行为
    'push_enabled' => true,

    /**
     * 插件 Composer 依赖一键安装（合并进基座根 vendor）。
     * 安全敏感（等于允许插件引入任意代码 + 改动整站依赖），默认关闭，仅受信/开发环境灰度开启。
     *   composer_enabled  总开关：关则 install 被拒，check 仍可只读检测
     *   composer_bin      composer 可执行文件（绝对路径或 composer.phar），默认自动探测 PATH 上的 composer
     *   composer_home     composer 家目录（缓存/config 存放）；worker 无 HOME 时必需，留空=系统临时目录
     *   composer_timeout  单次安装最长秒数，超时主动 kill
     *   composer_allowlist 包前缀白名单（如 ["tinywan/","topthink/"]）；空=一律拒绝（fail-closed），
     *                     开启 composer_enabled 前必须先填，否则插件与主程序依赖都装不上
     */
    'composer_enabled' => false,
    'composer_bin' => 'composer',
    'composer_home' => '',
    'composer_timeout' => 600,
    'composer_allowlist' => [],

    /**
     * 服务接管点声明（默认实现类 FQN 列表，须实现 ServiceProviderInterface，
     * 由实现类自带 key()/default() 声明）
     * ServiceRegistry 首次 make() 时懒加载：key => 默认实现自动登记（priority=0）
     */
    'providers' => [
        app\service\upload\DefaultUploadConfigResolver::class,
    ],
];
