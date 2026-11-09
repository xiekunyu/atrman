<?php

namespace app\basic;

/**
 * 插件生命周期基类
 *
 * 所有插件的 Hook 类（plugin\{name}\Plugin）建议继承本类：
 * - 显式获得完整生命周期契约，按需覆写，未关心的钩子保留默认空实现即可；
 * - 基座通过 PluginManager 以 method_exists 调度，即使不继承本类也能运行（向后兼容）。
 *
 * ⚠️ boot() 与其余钩子的关键区别：
 * - install/enable/disable/destroy 等只在"状态变更的那一次"触发；
 * - boot() 由基座在【每个 worker 进程启动时】(Webman\Bootstrap::start / onWorkerStart)
 *   对每个"已启用"插件各调用一次。因此凡是需要"每次进程启动都要重新装配"的运行时注册，
 *   都必须写在 boot() 里，而不是 install/enable。典型场景：
 *     IdentityService::registerProvider / registerExtender 等静态内存注册，
 *     进程重启后静态状态会丢失，只能靠 boot() 在每个进程重新登记。
 */
abstract class BasePlugin
{
    /**
     * 运行时装配钩子：基座在每个 worker 进程启动时，对每个「已启用」插件各调用一次。
     * 用于注册 IdentityService provider/extender、事件监听、缓存预热等进程级静态装配。
     * （新启用的插件需进程重启后其 boot() 才会在各 worker 生效）
     *
     * @param array $plugin 插件记录（am_plugin_app 一行）
     */
    public function boot(array $plugin = []): void
    {
    }

    /** 插件初始化：仅在 PluginManager::reload() 重新读取清单后调用；install/enable/upgrade 均不触发。 */
    public function init(array $plugin = []): void
    {
    }

    public function beforeInstall(array $plugin = []): void
    {
    }

    public function afterInstall(array $plugin = []): void
    {
    }

    public function beforeEnable(array $plugin = []): void
    {
    }

    public function afterEnable(array $plugin = []): void
    {
    }

    public function beforeDisable(array $plugin = []): void
    {
    }

    public function afterDisable(array $plugin = []): void
    {
    }

    /** 卸载前。 */
    public function beforeUninstall(array $plugin = []): void
    {
    }

    /** 升级前。 */
    public function beforeUpgrade(array $plugin = []): void
    {
    }

    /** 升级后。 */
    public function afterUpgrade(array $plugin = []): void
    {
    }

    /** 卸载时清理插件自有数据。 */
    public function destroy(array $plugin = []): void
    {
    }

    /**
     * 禁用/卸载时的运行时清理钩子（基类默认空实现，子类按需覆写）。
     *
     * 触发时机：
     * - 禁用：在 PluginManager::disable 的事务提交后、各 Registry 按 owner 清理前调用；
     * - 卸载：在 removePluginFiles 之前调用（插件目录仍存在，可安全访问文件/外部资源）。
     *
     * 典型用途：关闭插件自持有的外部连接、释放文件锁、通知外部系统等。
     * 注：静态注册（EventRegistry/ServiceRegistry/AdapterRegistry 等）的清理由基座
     * 在各 Registry 的 forgetByOwner 统一处理，插件无需在 shutdown 内重复清理。
     *
     * @param array $plugin 插件记录
     */
    public function shutdown(array $plugin = []): void
    {
    }

    /**
     * 声明备份 restore.sql 需包含的插件业务表（表名必须以 "{pluginName}_" 前缀）。
     * 基类默认返回空数组；子类按需覆写，返回非数组或非法前缀会被基座校验拦截。
     *
     * @return string[]
     */
    public function backupTables(array $plugin = []): array
    {
        return [];
    }

    /**
     * 便捷 helper：拼插件自有表名，统一 "{name}_" 前缀约定。
     * 例：$this->table('list', $plugin) => 'am_todo_list'（$plugin['table_prefix'] 或 name 提供前缀）。
     */
    protected function table(string $suffix, array $plugin = []): string
    {
        // 默认前缀即插件名 + 下划线（插件名如 am_todo 本身已含 am_，不可再叠加）
        $prefix = (string)($plugin['table_prefix'] ?? (($plugin['name'] ?? '') . '_'));
        return $prefix . ltrim($suffix, '_');
    }
}
