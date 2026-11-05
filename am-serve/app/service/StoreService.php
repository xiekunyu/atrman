<?php

namespace app\service;

use app\exception\ApiException;
use app\model\plugin\App as Plugin;
use app\model\system\Config;
use app\service\provider\MockStoreProvider;
use app\service\provider\RemoteStoreProvider;
use app\service\provider\StoreProvider;
use app\utils\Directory;
use app\utils\Security;
use support\Log;
use think\facade\Db;

/**
 * 应用商店服务
 *
 * 统一承载应用市场全部业务：
 * - 浏览：列表/详情/分类/更新检查（数据来自 StoreProvider，不落本地库）
 * - 市场账号：登录绑定、凭证加密存储、自动续期、解绑
 * - 实例身份：instance_id 生成与读取
 * - 下载安装：ticket 凭证流程（下载地址不出后端）、完整性校验、付费包验签
 * - License：付费应用授权写入、本地校验、心跳（每周一次，打开应用时触发）
 *
 * 免费应用无签名/License/心跳；付费应用（license_required=1）全流程管控。
 */
class StoreService
{
    /** 市场配置在 system_config 中的 app_name 分组 */
    protected const CONFIG_APP = 'market';

    /** License 状态：有效 */
    public const LICENSE_VALID = 1;

    /** License 状态：过期 */
    public const LICENSE_EXPIRED = 0;

    /** License 状态：吊销 */
    public const LICENSE_REVOKED = -1;

    /** License 状态：本地校验失败（缺失/签名不符/实例不符） */
    public const LICENSE_INVALID = -2;

    protected static ?StoreProvider $provider = null;

    // ─────────────────────── Provider ───────────────────────

    /** 获取数据源提供者 */
    public static function getProvider(): StoreProvider
    {
        if (!self::$provider) {
            $config = (string)config('store.provider', 'mock');
            self::$provider = $config === 'remote' ? new RemoteStoreProvider() : new MockStoreProvider();
        }
        return self::$provider;
    }

    /** 设置数据源提供者（用于测试） */
    public static function setProvider(StoreProvider $provider): void
    {
        self::$provider = $provider;
    }

    // ─────────────────────── 浏览 ───────────────────────

    /** 获取应用列表（合并本地安装状态，不含任何下载地址）
     *  支持分页：page / limit 参数，返回 ['list' => [...], 'total' => int, 'page' => int] */
    public static function getList(array $params = []): array
    {
        $list = self::getProvider()->list($params);
        if (empty($list)) {
            return ['list' => [], 'total' => 0, 'page' => (int)($params['page'] ?? 1)];
        }

        $names = array_column($list, 'name');
        $localMap = [];
        $localPlugins = Plugin::whereIn('name', $names)->whereIn('status', [0, 1])->select();
        foreach ($localPlugins as $plugin) {
            $localMap[$plugin->name] = $plugin;
        }

        foreach ($list as &$app) {
            $local = $localMap[$app['name']] ?? null;
            $app['installed'] = $local !== null;
            $app['local_version'] = $local->version ?? '';
            $app['local_status'] = $local ? (int)$local->status : -1;
            $app['plugin_id'] = $local->id ?? null;
            $app['has_update'] = $local !== null
                && PluginManifestService::comparePluginVersion((string)$app['version'], (string)$local->version) > 0;
            $app['license_status'] = $local ? (self::licenseState($local)['status'] ?? null) : null;
        }
        unset($app);

        // “已安装”过滤（仅展示本地已安装的应用）
        if (!empty($params['installed'])) {
            $list = array_values(array_filter($list, fn($app) => !empty($app['installed'])));
        }

        // 分页
        $total = count($list);
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 20));
        $offset = ($page - 1) * $limit;
        $list = array_slice($list, $offset, $limit);

        return ['list' => $list, 'total' => $total, 'page' => $page];
    }

    /** 获取应用详情 */
    public static function getDetail(string $appId): ?array
    {
        $app = self::getProvider()->detail($appId);
        if (!$app) {
            return null;
        }

        $local = Plugin::where('name', (string)($app['name'] ?? ''))->find();
        $app['installed'] = $local !== null && in_array((int)$local->status, [0, 1], true);
        $app['local_version'] = $local->version ?? '';
        $app['local_status'] = $local ? (int)$local->status : -1;
        $app['plugin_id'] = $local->id ?? null;
        $app['has_update'] = (bool)$app['installed']
            && PluginManifestService::comparePluginVersion((string)$app['version'], (string)$local->version) > 0;
        $app['license_status'] = $local ? (self::licenseState($local)['status'] ?? null) : null;

        return $app;
    }

    /** 获取分类列表 */
    public static function getCategories(): array
    {
        return self::getProvider()->categories();
    }

    /** 获取应用版本历史列表 */
    public static function getVersions(string $appId): array
    {
        return self::getProvider()->versions($appId);
    }

    /** 检查所有已安装应用的更新 */
    public static function checkAllUpdates(): array
    {
        $installed = Plugin::whereIn('status', [0, 1])->select()->toArray();
        if (empty($installed)) {
            return [];
        }
        return self::getProvider()->checkUpdate($installed);
    }

    /** 基座更新检测与在线升级总开关（.env 的 STORE_SYSTEM_UPDATE）。 */
    public static function systemUpdateEnabled(): bool
    {
        return (bool)config('store.system_update_enabled', true);
    }

    /** 检查系统更新 */
    public static function checkSystemUpdate(): ?array
    {
        if (!self::systemUpdateEnabled()) {
            // 关闭时也返回「无更新」形状（调用方不必判 null）；基准版本用宽松读，不能因清单缺失打断页面
            return [
                'has_update' => false,
                'current_version' => PluginManifestService::getAppVersion(),
                'latest_version' => '',
                'changelog' => '',
                'release_date' => '',
                'upgrade' => ['mode' => 'none', 'packages' => []],
            ];
        }
        // 基准版本读不到就中止，避免拿空版本号去市场换回一批"可升级"包
        return self::getProvider()->systemUpdate(PluginManifestService::requireAppVersion());
    }

    /**
     * 检测应用标识是否合法且不与应用市场冲突（创建应用时调用）。
     *
     * 校验维度（逐层收紧）：
     * 1. 本地基础规则（PluginManifestService::checkNameRule）：格式 am_ 前缀 + 小写字母/数字/下划线、
     *    自定义命名最小长度、系统保留名（am_system/am_app/am_store/am_member 等）。
     * 2. 本地已安装插件冲突：不得与当前实例已装插件同名。
     * 3. 应用市场全局唯一：经 StoreProvider 查询市场全部应用标识（含未上架/软删），全局不可重复。
     *
     * fail-open：市场不可达 / 无法明确回答时不阻断全局查重，仅当市场明确返回占用才判为冲突；
     * 但基础规则与本地冲突为强校验，不受市场可达性影响。
     *
     * @return array{valid:bool, name:string, available:bool, reason:string, message:string}
     */
    public static function checkIdentifier(string $rawName): array
    {
        $name = \normalizePluginName($rawName);

        // 1. 本地基础规则：格式 / 最小长度 / 保留名
        if ($err = PluginManifestService::checkNameRule($name)) {
            return [
                'valid' => false,
                'name' => $name,
                'available' => false,
                'reason' => 'format',
                'message' => $err,
            ];
        }

        // 2. 本地已安装插件冲突
        if (!Plugin::where('name', $name)->findOrEmpty()->isEmpty()) {
            return [
                'valid' => false,
                'name' => $name,
                'available' => false,
                'reason' => 'installed',
                'message' => '应用标识与当前实例已安装插件冲突，请更换其他标识',
            ];
        }

        // 3. 应用市场全局唯一（查询市场全部应用标识）
        try {
            $check = self::getProvider()->checkName($name);
        } catch (\Throwable $e) {
            // 市场不可达或无法明确判定：放行（fail-open），仅记录日志
            Log::warning('应用标识市场查重跳过（市场不可达）：' . $e->getMessage());
            $check = ['available' => true, 'reason' => '', 'message' => '标识可用'];
        }

        if (empty($check['available'])) {
            return [
                'valid' => false,
                'name' => $name,
                'available' => false,
                'reason' => (string)($check['reason'] ?? 'market'),
                'message' => (string)($check['message'] ?? '应用标识已被应用市场占用，请更换其他标识'),
            ];
        }

        return [
            'valid' => true,
            'name' => $name,
            'available' => true,
            'reason' => '',
            'message' => '标识可用',
        ];
    }
    // ─────────────────────── 市场账号 ───────────────────────

    /**
     * 登录并绑定应用市场账号
     *
     * 凭证 AES-256 加密后存 system_config（app_name=market），
     * 加密密钥从 .env 的 JWT_ACCESS_KEY 派生，数据库备份不含明文。
     */
    public static function marketLogin(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            throw new ApiException('请输入市场账号和密码');
        }

        $account = self::getProvider()->login(['username' => $username, 'password' => $password]);
        self::saveMarketAccount($account);

        return self::marketStatus();
    }

    /** 获取市场账号绑定状态（只返回掩码信息，永不返回 token 明文） */
    public static function marketStatus(): array
    {
        $account = self::readMarketAccount();
        if (!$account) {
            return [
                'bound' => false,
                'instance_id' => self::getInstanceId(),
                'provider' => self::getProvider()->name(),
            ];
        }

        return [
            'bound' => true,
            'username' => self::maskUsername((string)($account['username'] ?? '')),
            'nickname' => (string)($account['nickname'] ?? ''),
            'expires_at' => (int)($account['expires_at'] ?? 0),
            'expired' => (int)($account['expires_at'] ?? 0) < time(),
            'instance_id' => self::getInstanceId(),
            'provider' => self::getProvider()->name(),
        ];
    }

    /** 解绑市场账号 */
    public static function marketLogout(): bool
    {
        $account = self::readMarketAccount();
        if ($account && !empty($account['token'])) {
            try {
                self::getProvider()->logout((string)$account['token']);
            } catch (\Throwable $e) {
                Log::warning('市场账号注销请求失败（已忽略）：' . $e->getMessage());
            }
        }
        self::deleteMarketConfig('account');
        return true;
    }

    /** 获取有效的市场 token（临期自动用 refresh_token 续期） */
    public static function getMarketToken(): string
    {
        $account = self::readMarketAccount();
        if (!$account || empty($account['token'])) {
            throw new ApiException('请先绑定应用插件账号');
        }

        if ((int)($account['expires_at'] ?? 0) > time() + 60) {
            return (string)$account['token'];
        }

        if (!empty($account['refresh_token'])) {
            $new = self::getProvider()->refreshToken((string)$account['refresh_token']);
            if ($new) {
                $new['username'] = $new['username'] ?: (string)($account['username'] ?? '');
                $new['nickname'] = $new['nickname'] ?: (string)($account['nickname'] ?? '');
                self::saveMarketAccount($new);
                return (string)$new['token'];
            }
        }

        throw new ApiException('市场账号凭证已过期，请重新绑定');
    }

    /** 保存市场账号凭证（加密） */
    protected static function saveMarketAccount(array $account): void
    {
        $payload = json_encode([
            'token' => (string)($account['token'] ?? ''),
            'refresh_token' => (string)($account['refresh_token'] ?? ''),
            'expires_at' => (int)($account['expires_at'] ?? 0),
            'username' => (string)($account['username'] ?? ''),
            'nickname' => (string)($account['nickname'] ?? ''),
            'bound_at' => time(),
        ], JSON_UNESCAPED_UNICODE);

        self::setMarketConfig('account', Security::encrypt((string)$payload, Security::credentialKey()));
    }

    /** 读取并解密市场账号凭证 */
    protected static function readMarketAccount(): ?array
    {
        $encrypted = self::getMarketConfig('account');
        if (!$encrypted) {
            return null;
        }
        $plain = Security::decrypt((string)$encrypted, Security::credentialKey());
        if ($plain === null) {
            return null;
        }
        $account = json_decode($plain, true);
        return is_array($account) && !empty($account['token']) ? $account : null;
    }

    /** 用户名掩码 */
    protected static function maskUsername(string $username): string
    {
        $len = mb_strlen($username);
        if ($len <= 2) {
            return mb_substr($username, 0, 1) . '**';
        }
        return mb_substr($username, 0, 2) . str_repeat('*', max(2, $len - 3)) . mb_substr($username, -1);
    }

    // ─────────────────────── 实例身份 ───────────────────────

    /** 获取实例 ID（首次访问自动生成并持久化） */
    public static function getInstanceId(): string
    {
        $instanceId = self::getMarketConfig('instance_id');
        if ($instanceId) {
            return (string)$instanceId;
        }
        $instanceId = 'inst_' . Security::randomHex(16);
        self::setMarketConfig('instance_id', $instanceId);
        return $instanceId;
    }

    /** 获取当前站点域名（用于 License 域名绑定，可为空） */
    public static function getDomain(): string
    {
        $appUrl = (string)env('APP_URL', '');
        if ($appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if ($host) {
                return (string)$host;
            }
        }
        try {
            return (string)(request()->host(true) ?: '');
        } catch (\Throwable) {
            return '';
        }
    }
    // ─────────────────────── 安装 / 更新 ───────────────────────

    /**
     * 安装应用（ticket 凭证流程）
     *
     * 流程：市场账号校验 → 请求下载凭证 → 后端下载 → sha256 校验
     *      → 付费包验签 → PluginManager 安装 → 写入 License
     *
     * @param string $appId 应用标识
     * @param string $version 指定安装版本，空字符串表示最新版
     */
    public static function install(string $appId, string $version = ''): array
    {
        $zipPath = '';
        try {
            $provider = self::getProvider();
            $app = $provider->detail($appId);
            if (!$app || empty($app['name'])) {
                throw new ApiException('应用不存在');
            }

            $existing = Plugin::where('name', (string)$app['name'])->find();
            if ($existing && in_array((int)$existing->status, [0, 1], true)) {
                throw new ApiException('应用已安装');
            }
            // 默认装最新版：下载前先判基座兼容（与升级同口径）；
            // 指定历史版本时详情里的兼容要求不代表该版本，交由包内 manifest 在校验阶段兜底
            if ($version === '') {
                self::assertTargetCompatible((array)$app);
            }

            // 指定版本时覆盖默认版本
            $installVersion = $version ?: (string)$app['version'];
            $ticket = self::requestTicket($appId, $installVersion);
            if (!empty($ticket['need_purchase'])) {
                return self::purchaseRequiredResult($ticket);
            }

            $zipPath = self::downloadByTicket($ticket, (string)$app['name'], $installVersion);

            // 完整性门槛：市场包一律以官方发布清单为准，与是否付费无关。
            // 让 license_required 顺带决定要不要校验，等于免费应用可以装任意未签名代码。
            ReleaseManifestService::check($zipPath, (string)$app['name'], $installVersion, 0);

            $licenseRequired = (bool)($ticket['license_required'] ?? false);
            if ($licenseRequired) {
                $localCheck = self::verifyLicenseData((array)($ticket['license'] ?? []), (string)$app['name']);
                if (!$localCheck['valid']) {
                    throw new ApiException('市场签发的授权无效：' . $localCheck['message']);
                }
            }

            $plugin = PluginManager::installPackage($zipPath);

            if ($licenseRequired) {
                self::writeLicenseFile((string)$plugin->name, (array)$ticket['license']);
                self::applyLicenseState($plugin, (array)$ticket['license']);
            }

            return ['success' => true, 'message' => '安装成功', 'data' => PluginManifestService::enrichPlugin($plugin)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        } finally {
            if ($zipPath !== '' && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * 更新应用（非级联薄封装）：目标已禁用时直接覆盖安装，
     * 启用中则转共享编排（自动停用 → 升级 → 自动恢复），不再要求人工先禁用。
     */
    public static function update(string $appId): array
    {
        $zipPath = '';
        try {
            $provider = self::getProvider();
            $app = $provider->detail($appId);
            if (!$app || empty($app['name'])) {
                throw new ApiException('应用不存在');
            }

            $plugin = Plugin::where('name', (string)$app['name'])->find();
            if (!$plugin) {
                throw new ApiException('应用未安装');
            }
            if (PluginManifestService::comparePluginVersion((string)$app['version'], (string)$plugin->version) <= 0) {
                throw new ApiException('当前已是最新版本');
            }
            // 下载前硬守卫：预检弹窗拦不住「直接打 /plugin/store/update」的调用，
            // 不兼容时在此中止，绝不走到 ticket → 下载 → installPackage 才报错
            self::assertTargetCompatible((array)$app);
            if ((int)$plugin->status === 1) {
                // 启用中：转共享编排（自动停用 → 升级 → 自动恢复），失败结果原样上抛
                $auto = self::cascadeUpdate($appId);
                if (empty($auto['data']['ok'])) {
                    return $auto;
                }
                return [
                    'success' => true,
                    'message' => (string)$auto['message'],
                    'data'    => $auto['data']['plugin'] ?? [],
                ];
            }

            $ret = self::performInstall($appId, (array)$app);
            if (!empty($ret['need_purchase'])) {
                return self::purchaseRequiredResult((array)$ret['ticket']);
            }
            $zipPath = (string)($ret['zipPath'] ?? '');
            $plugin = $ret['plugin'] ?? null;

            return [
                'success' => true,
                'message' => '更新成功',
                'data' => $plugin ? PluginManifestService::enrichPlugin($plugin) : [],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        } finally {
            if ($zipPath !== '' && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * 下载前兼容性硬守卫：市场详情的 support_system_version 高于当前基座即拒绝。
     * 口径与 updatePreflight() / PluginManifestService::getCompatibilityInfo() 一致，
     * 空声明按兼容处理。供绕过预检的直连更新路径兜底。
     */
    private static function assertTargetCompatible(array $app): void
    {
        $required = trim((string)($app['support_system_version'] ?? ''));
        if ($required === '') {
            return;
        }
        $base = PluginManifestService::requireAppVersion();
        if (!\versionGte($base, $required)) {
            throw new ApiException('v' . (string)($app['version'] ?? '') . ' 要求基座版本 ≥ v' . $required
                . '，当前基座 v' . $base . '，请先升级基座后再更新本应用');
        }
    }

    /**
     * 下载 + 校验 + 覆盖安装 + License 落库（update 与 cascadeUpdate 共用）。
     *
     * 返回：need_purchase 时 ['need_purchase'=>true,'ticket'=>array]（未下载）；
     *      成功时 ['plugin'=>?Plugin,'zipPath'=>string,'license_required'=>bool]。
     * 抛异常表示失败（内部已清理临时包，调用方负责回滚）。
     */
    private static function performInstall(string $appId, array $app): array
    {
        $ticket = self::requestTicket($appId);
        if (!empty($ticket['need_purchase'])) {
            return ['need_purchase' => true, 'ticket' => $ticket];
        }

        $zipPath = self::downloadByTicket($ticket, (string)$app['name'], (string)$app['version']);
        try {
            // 与 install() 同口径：以发布清单为准，license_required 只管授权不管完整性
            ReleaseManifestService::check($zipPath, (string)$app['name'], (string)$app['version'], 0);

            $licenseRequired = (bool)($ticket['license_required'] ?? false);
            if ($licenseRequired) {
                $localCheck = self::verifyLicenseData((array)($ticket['license'] ?? []), (string)$app['name']);
                if (!$localCheck['valid']) {
                    throw new ApiException('市场签发的授权无效：' . $localCheck['message']);
                }
            }

            PluginManager::installPackage($zipPath, true);

            $plugin = Plugin::where('name', (string)$app['name'])->find();
            if ($licenseRequired && $plugin) {
                self::writeLicenseFile((string)$plugin->name, (array)$ticket['license']);
                self::applyLicenseState($plugin, (array)$ticket['license']);
            }

            return ['plugin' => $plugin, 'zipPath' => $zipPath, 'license_required' => $licenseRequired];
        } catch (\Throwable $e) {
            // 失败：立即清理临时包再上抛（使调用方无需重复处理）
            if ($zipPath !== '' && is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        }
    }

    /**
     * 级联升级预检：判定是否需要级联、将临时停用哪些依赖方、可否级联。
     *
     * 停用范围 / 可编排性由 PluginManager::preflightUpgrade 给出——市场升级与基座本地
     * 安装包升级共用同一判定，唯一阻断项是依赖成环（启用中只需自动停用，不再要求人工先禁用）。
     *
     * @return array{plugin_name:string,has_update:bool,current_version:string,target_version:string,target_title:string,need_cascade:bool,will_disable:array,can_cascade:bool,block_reason:string,package_size:int,changelog:string,release_date:string,support_system_version:string,current_base_version:string,compatible:bool,compatibility_message:string,need_purchase?:bool,price?:mixed,buy_url?:string}
     */
    public static function updatePreflight(string $appId): array
    {
        $provider = self::getProvider();
        $app = $provider->detail($appId);
        if (!$app || empty($app['name'])) {
            throw new ApiException('应用不存在');
        }
        $name = (string)$app['name'];
        $plugin = Plugin::where('name', $name)->find();
        if (!$plugin) {
            throw new ApiException('应用未安装');
        }

        $currentVersion = (string)$plugin->version;
        $targetVersion = (string)$app['version'];
        $hasUpdate = PluginManifestService::comparePluginVersion($targetVersion, $currentVersion) > 0;

        // 基座兼容性（下载前硬门槛）：市场详情带目标版本的最低基座要求，
        // 空串=未声明按兼容处理，口径与 PluginManifestService::getCompatibilityInfo 一致。
        // 必须在请求 ticket / 下载之前拦住——包下载后才在 installPackage() 报错，
        // 用户既装不上也谈不上回退体验。
        $baseVersion = PluginManifestService::getAppVersion();
        $requiredBase = trim((string)($app['support_system_version'] ?? ''));
        $compatible = $requiredBase === '' || \versionGte($baseVersion, $requiredBase);
        $compatMessage = $requiredBase === ''
            ? '未声明最低基座版本，默认兼容'
            : ($compatible
                ? '兼容当前基座版本（v' . $baseVersion . '）'
                : '要求基座版本 ≥ v' . $requiredBase . '，当前基座 v' . $baseVersion);

        $result = [
            'plugin_name'          => $name,
            'has_update'           => $hasUpdate,
            'current_version'      => $currentVersion,
            'target_version'       => $targetVersion,
            'target_title'         => (string)($app['title'] ?? $plugin->title ?? $name),
            'need_cascade'         => false,
            'will_disable'         => [],
            'can_cascade'          => true,
            'block_reason'         => '',
            // 升级确认弹窗展示项（版本号 / 包大小 / 更新内容 / 兼容性）
            'package_size'         => (int)($app['package_size'] ?? 0),
            'changelog'            => (string)($app['changelog'] ?? ''),
            'release_date'         => (string)($app['release_date'] ?? ''),
            'support_system_version' => $requiredBase,
            'current_base_version' => $baseVersion,
            'compatible'           => $compatible,
            'compatibility_message' => $compatMessage,
        ];
        if (!$hasUpdate) {
            return $result;
        }

        // 不兼容是最硬的阻断：短路掉级联编排判定与付费 ticket 请求，直接给出升级基座的引导
        if (!$compatible) {
            $result['can_cascade'] = false;
            $result['block_reason'] = 'v' . $targetVersion . ' 不兼容当前基座：' . $compatMessage
                . '，请先在「系统与升级」升级基座后再更新本应用';
            return $result;
        }

        $upgrade = PluginManager::preflightUpgrade($name, $plugin);
        $result['need_cascade'] = $upgrade['need_cascade'];
        $result['will_disable'] = $upgrade['will_disable'];
        $result['can_cascade'] = $upgrade['can_upgrade'];
        if (!$upgrade['can_upgrade']) {
            $result['block_reason'] = $upgrade['block_reason'];
            return $result;
        }

        // 付费未购买：预检阶段即提示购买，避免级联中途才失败
        $ticket = self::requestTicket($appId);
        if (!empty($ticket['need_purchase'])) {
            $result['can_cascade'] = false;
            $result['need_purchase'] = true;
            $result['price'] = $ticket['price'] ?? 0;
            $result['buy_url'] = $ticket['buy_url'] ?? '';
            $result['block_reason'] = (string)($ticket['message'] ?? '该应用为付费应用，当前市场账号未购买');
        }

        return $result;
    }

    /**
     * 级联升级：与市场 / 基座本地安装包共用 PluginManager::runUpgrade 编排
     * （叶子先停 → 升级中心插件 → 逆序自动重启依赖方，失败全量回滚）。
     *
     * 本方法只保留市场侧特有的事：预检复核、下载 / 验签 / License 落库、临时包清理。
     */
    public static function cascadeUpdate(string $appId): array
    {
        // 1. 复核预检
        $preflight = self::updatePreflight($appId);
        if (empty($preflight['has_update'])) {
            return ['success' => false, 'message' => '当前已是最新版本', 'data' => ['ok' => false]];
        }
        if (!empty($preflight['need_purchase'])) {
            return ['success' => false, 'message' => $preflight['block_reason'], 'data' => [
                'ok' => false, 'need_purchase' => true, 'price' => $preflight['price'] ?? 0, 'buy_url' => $preflight['buy_url'] ?? '',
            ]];
        }
        if (empty($preflight['can_cascade'])) {
            return ['success' => false, 'message' => $preflight['block_reason'], 'data' => ['ok' => false]];
        }

        $name = (string)$preflight['plugin_name'];
        $zipPath = '';
        $app = [];

        $result = PluginManager::runUpgrade(
            $name,
            // 安装：用锁内已复核过的详情下包并覆盖安装（此时目标已被编排停用，校验天然通过）
            function () use ($appId, &$app, &$zipPath): array {
                $ret = self::performInstall($appId, $app);
                if (!empty($ret['need_purchase'])) {
                    throw new ApiException((string)($ret['ticket']['message'] ?? '该应用为付费应用，当前市场账号未购买'));
                }
                $zipPath = (string)($ret['zipPath'] ?? '');
                return $ret;
            },
            $preflight['will_disable'],
            // 前置校验：预检后市场若发了新版本则中止（此时未动任何文件与库，无需回滚），避免「提示 A 实装 B」
            function () use ($appId, $preflight, $name, &$app): void {
                $app = (array)self::getProvider()->detail($appId);
                if ((string)($app['name'] ?? '') === '') {
                    throw new ApiException('应用不存在');
                }
                if (PluginManifestService::comparePluginVersion((string)($app['version'] ?? ''), (string)$preflight['target_version']) !== 0) {
                    throw new ApiException('市场版本在预检后发生变化，请重新发起升级');
                }
            }
        );

        // 版本跨度供前端结果面板展示（市场特有信息，runUpgrade 不关心）
        if (!empty($result['data']['ok'])) {
            $result['data']['target'] = [
                'name'         => $name,
                'from_version' => $preflight['current_version'],
                'to_version'   => $preflight['target_version'],
            ];
        }

        // 临时包由市场侧清理（本地安装包路径属文件库，不可删）
        if ($zipPath !== '' && is_file($zipPath)) {
            @unlink($zipPath);
        }

        return $result;
    }

    /** 未购买结果（前端据此展示购买引导） */
    protected static function purchaseRequiredResult(array $ticket): array
    {
        return [
            'success' => false,
            'message' => (string)($ticket['message'] ?? '该应用为付费应用，当前市场账号未购买'),
            'data' => [
                'need_purchase' => true,
                'price' => $ticket['price'] ?? 0,
                'buy_url' => $ticket['buy_url'] ?? '',
            ],
        ];
    }

    /** 请求下载凭证（统一携带账号 token 与实例身份）
     * @param string $version 指定版本，空字符串表示最新版 */
    protected static function requestTicket(string $appId, string $version = ''): array
    {
        $context = [
            'market_token' => self::getMarketToken(),
            'instance_id' => self::getInstanceId(),
            'domain' => self::getDomain(),
        ];
        if ($version !== '') {
            $context['version'] = $version;
        }
        return self::getProvider()->requestDownloadTicket($appId, $context);
    }

    /**
     * 请求主程序包下载凭证（升级链每个待装包各签一张，真实路径只在后端流转）
     *
     * @param array $package systemUpdate 返回的 upgrade.packages 单条目
     */
    public static function requestSystemDownloadTicket(array $package): array
    {
        return self::getProvider()->requestSystemDownloadTicket($package, [
            'market_token' => self::getMarketToken(),
            'instance_id' => self::getInstanceId(),
            'domain' => self::getDomain(),
        ]);
    }

    /**
     * 按凭证下载主程序包到本地 runtime（复用插件包同样的三态下载 + sha256 强校验）
     */
    public static function downloadSystemPackage(array $ticket, string $version): string
    {
        return self::downloadByTicket($ticket, 'atrman', $version);
    }

    /**
     * 按凭证下载安装包到本地 runtime
     *
     * 支持三种 url：本地绝对路径、站点相对路径、http(s) 远程地址；
     * 前两种（Mock / 开发态）一律限定在 runtime 内。
     */
    protected static function downloadByTicket(array $ticket, string $name, string $version): string
    {
        $url = (string)($ticket['url'] ?? '');
        if ($url === '') {
            throw new ApiException('下载凭证无效');
        }

        $packageRoot = FileService::pluginPackageRoot();
        Directory::ensureDir($packageRoot);
        $zipPath = $packageRoot . '/' . $name . '_' . $version . '_' . uniqid() . '.zip';

        // 本地包先定源再统一约束：站点相对路径同样只允许落在 runtime 内，
        // 否则凭证可把 base_path() 下任意可读文件（含 .env）复制进包目录
        $sourcePath = '';
        if (is_file($url)) {
            $sourcePath = (string)realpath($url);
        } elseif (str_starts_with($url, '/') && is_file(base_path() . $url)) {
            $sourcePath = (string)realpath(base_path() . $url) ?: (base_path() . $url);
        }

        if ($sourcePath !== '') {
            Directory::assertPathWithin($sourcePath, runtime_path());
            if (!copy($sourcePath, $zipPath)) {
                throw new ApiException('复制安装包失败');
            }
        } else {
            self::downloadRemote($url, $zipPath);
        }

        // 铁律：校验值缺失/非法直接拒绝，不允许「留空即不校」（与主程序升级链同口径），
        // 否则发布时漏填 sha256 就等于把完整性保护交回给传输层
        $expected = strtolower((string)($ticket['sha256'] ?? ''));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            @unlink($zipPath);
            throw new ApiException('下载凭证缺少有效的 sha256 校验值，已中止');
        }
        if (!hash_equals($expected, strtolower(Security::fileSha256($zipPath)))) {
            @unlink($zipPath);
            throw new ApiException('安装包完整性校验失败（sha256 不匹配）');
        }

        return $zipPath;
    }

    /** 远程下载安装包（仅允许 http/https） */
    protected static function downloadRemote(string $url, string $zipPath): void
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ApiException('不支持的下载地址协议');
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => (int)config('store.download_timeout', 300)]);
            $client->request('GET', $url, ['sink' => $zipPath, 'http_errors' => true]);
        } catch (\Throwable $e) {
            is_file($zipPath) && @unlink($zipPath);
            throw new ApiException('下载安装包失败：' . $e->getMessage());
        }

        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            throw new ApiException('下载安装包失败：文件为空');
        }
    }
    // ─────────────────────── License 授权 ───────────────────────

    /** 写入 License 文件到插件目录 */
    public static function writeLicenseFile(string $pluginName, array $license): void
    {
        $dir = FileService::pluginRoot() . '/' . $pluginName;
        if (!is_dir($dir)) {
            throw new ApiException('插件目录不存在，无法写入授权文件');
        }
        $result = @file_put_contents(
            $dir . '/license.json',
            (string)json_encode($license, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        if ($result === false) {
            throw new ApiException('授权文件写入失败');
        }
    }

    /** 读取插件目录下的 License 文件 */
    public static function readLicenseFile(string $pluginName): ?array
    {
        $path = FileService::pluginRoot() . '/' . $pluginName . '/license.json';
        if (!is_file($path)) {
            return null;
        }
        $license = json_decode((string)file_get_contents($path), true);
        return is_array($license) && !empty($license['signature']) ? $license : null;
    }

    /**
     * 校验 License 数据（签名/实例/域名/有效期）
     *
     * @return array{valid: bool, status: int, message: string}
     */
    public static function verifyLicenseData(array $license, string $pluginName): array
    {
        if (empty($license['signature'])) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '授权文件缺少签名'];
        }
        if ((string)($license['plugin'] ?? '') !== $pluginName) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '授权文件与插件标识不符'];
        }

        try {
            $keyInfo = self::getProvider()->signingPublicKey();
        } catch (\Throwable $e) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '验签公钥不可用：' . $e->getMessage()];
        }

        $alg = (string)($license['alg'] ?? $keyInfo['alg'] ?? Security::ALG_ED25519);
        if (!Security::verify(Security::canonicalPayload($license), (string)$license['signature'], (string)$keyInfo['public_key'], $alg)) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '授权签名验证失败'];
        }

        $instanceId = (string)($license['instance_id'] ?? '');
        if ($instanceId !== '' && !hash_equals($instanceId, self::getInstanceId())) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '授权与当前实例不匹配（禁止跨服务器使用）'];
        }

        $domain = (string)($license['domain'] ?? '');
        $currentDomain = self::getDomain();
        if ($domain !== '' && $currentDomain !== '' && !hash_equals($domain, $currentDomain)) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '授权与当前域名不匹配'];
        }

        $expiresAt = (int)($license['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            return ['valid' => false, 'status' => self::LICENSE_EXPIRED, 'message' => '授权已过期，请续费后更新'];
        }

        return ['valid' => true, 'status' => self::LICENSE_VALID, 'message' => '授权有效'];
    }

    /** 校验插件 License（读文件 + 数据校验），付费插件专用 */
    public static function verifyLicense(Plugin $plugin): array
    {
        $license = self::readLicenseFile((string)$plugin->name);
        if (!$license) {
            return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '缺少授权文件（license.json），付费应用需从应用插件安装'];
        }
        return self::verifyLicenseData($license, (string)$plugin->name);
    }

    /**
     * 启用前授权断言（PluginManager::enable 调用）
     *
     * 仅对付费插件（config.license.required=1）生效，校验失败抛异常阻止启用。
     */
    public static function assertLicenseForEnable(Plugin $plugin): void
    {
        if (!self::isLicenseRequired($plugin)) {
            return;
        }
        $result = self::verifyLicense($plugin);
        if (!$result['valid']) {
            throw new ApiException('付费应用授权校验失败：' . $result['message']);
        }
    }

    /** 判断插件是否为付费授权插件 */
    public static function isLicenseRequired(Plugin $plugin): bool
    {
        $state = self::licenseState($plugin);
        return (bool)($state['required'] ?? false);
    }

    /** 读取插件的 License 运行状态（plugin_app.config['license']） */
    public static function licenseState(Plugin $plugin): array
    {
        $config = \normalizeArray($plugin->config);
        $license = $config['license'] ?? [];
        return is_array($license) ? $license : [];
    }

    /** 将 License 信息写入 plugin_app.config（运行状态缓存，用于展示与心跳节流） */
    public static function applyLicenseState(Plugin $plugin, array $license): void
    {
        $config = \normalizeArray($plugin->config);
        $config['license'] = [
            'required' => true,
            'license_id' => (string)($license['license_id'] ?? ''),
            'license_type' => (string)($license['license_type'] ?? 'perpetual'),
            'issued_at' => (int)($license['issued_at'] ?? 0),
            'expires_at' => (int)($license['expires_at'] ?? 0),
            'status' => self::LICENSE_VALID,
            'last_check_time' => time(),
            'fail_count' => 0,
            'first_fail_time' => 0,
            'last_message' => '安装时授权校验通过',
        ];
        $plugin->save(['config' => $config]);
    }

    /** 更新 License 运行状态字段 */
    protected static function updateLicenseState(Plugin $plugin, array $patch): void
    {
        $config = \normalizeArray($plugin->config);
        $license = is_array($config['license'] ?? null) ? $config['license'] : [];
        $config['license'] = array_merge($license, $patch);
        $plugin->save(['config' => $config]);
    }

    /** 授权列表（后台管理展示） */
    public static function licenseList(): array
    {
        $list = [];
        $plugins = Plugin::whereIn('status', [-1, 0, 1])->select();
        foreach ($plugins as $plugin) {
            $state = self::licenseState($plugin);
            if (empty($state['required'])) {
                continue;
            }
            $list[] = [
                'plugin_id' => $plugin->id,
                'name' => $plugin->name,
                'title' => $plugin->title,
                'version' => $plugin->version,
                'license_id' => (string)($state['license_id'] ?? ''),
                'license_type' => (string)($state['license_type'] ?? ''),
                'issued_at' => (int)($state['issued_at'] ?? 0),
                'expires_at' => (int)($state['expires_at'] ?? 0),
                'status' => (int)($state['status'] ?? self::LICENSE_INVALID),
                'last_check_time' => (int)($state['last_check_time'] ?? 0),
                'fail_count' => (int)($state['fail_count'] ?? 0),
                'last_message' => (string)($state['last_message'] ?? ''),
                'plugin_status' => (int)$plugin->status,
                'error_message' => (string)$plugin->error_message,
            ];
        }
        return $list;
    }
    // ─────────────────────── 心跳（每周一次，打开应用时触发） ───────────────────────

    /** 标记心跳已投递（原子更新，防止并发请求重复投递）
     *  节流基准为 last_dispatch_time（投递标记，缺失时回落 last_check_time 兼容存量数据），
     *  不提前推动 last_check_time（校验完成时间），保证投递/消费失败后下个窗口可重试
     *  @return bool 是否成功标记（false 表示已被其他请求抢先投递） */
    public static function markHeartbeatDispatched(Plugin $plugin): bool
    {
        $interval = (int)config('store.heartbeat.interval', 604800);
        $now = time();
        $affected = Db::table('am_plugin_app')
            ->where('id', $plugin->id)
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(config, '$.license.last_dispatch_time')), JSON_UNQUOTE(JSON_EXTRACT(config, '$.license.last_check_time')), 0) + {$interval} <= {$now}")
            ->update([
                'config' => Db::raw("JSON_SET(config, '$.license.last_dispatch_time', {$now})")
            ]);
        return $affected > 0;
    }

    /** 投递心跳任务到异步队列（不阻塞当前请求） */
    public static function dispatchHeartbeat(int $pluginId): void
    {
        try {
            \Webman\RedisQueue\Client::send('license_heartbeat', ['plugin_id' => $pluginId]);
        } catch (\Throwable $e) {
            // 队列不可用时仅记录日志，不影响业务请求
            Log::warning('License 心跳投递失败：' . $e->getMessage());
            // 投递失败回滚投递标记，下次打开应用即可重试（避免静默停摆到下个节流窗口）
            try {
                Db::table('am_plugin_app')
                    ->where('id', $pluginId)
                    ->update(['config' => Db::raw("JSON_SET(config, '$.license.last_dispatch_time', 0)")]);
            } catch (\Throwable $inner) {
                Log::warning('License 心跳投递标记回滚失败：' . $inner->getMessage());
            }
        }
    }

    /** 是否需要发起心跳（付费 + 启用中 + 距上次投递/校验超过间隔） */
    public static function shouldHeartbeat(Plugin $plugin): bool
    {
        if ((int)$plugin->status !== 1) {
            return false;
        }
        $state = self::licenseState($plugin);
        if (empty($state['required'])) {
            return false;
        }
        $interval = (int)config('store.heartbeat.interval', 604800);
        $last = max((int)($state['last_check_time'] ?? 0), (int)($state['last_dispatch_time'] ?? 0));
        return (time() - $last) >= $interval;
    }

    /**
     * 执行 License 心跳校验
     *
     * 本地校验先行（签名/实例/有效期），再在线校验（支持远程吊销/续期同步）。
     * 网络失败不锁定，进入宽限期；宽限期内连续失败超期才锁定插件。
     *
     * @return array{valid: bool, status: int, message: string}
     */
    public static function heartbeat(Plugin $plugin): array
    {
        // 1. 本地校验
        $local = self::verifyLicense($plugin);
        if (!$local['valid']) {
            self::lockPlugin($plugin, (int)$local['status'], (string)$local['message']);
            return $local;
        }

        // 2. 在线校验
        $state = self::licenseState($plugin);
        try {
            $marketToken = self::getMarketToken();
        } catch (\Throwable) {
            $marketToken = '';
        }

        try {
            $result = self::getProvider()->heartbeat([
                'market_token' => $marketToken,
                'instance_id' => self::getInstanceId(),
                'domain' => self::getDomain(),
                'license_id' => (string)($state['license_id'] ?? ''),
                'plugin' => (string)$plugin->name,
                'version' => (string)$plugin->version,
                'license_expires_at' => (int)($state['expires_at'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            $result = ['valid' => true, 'status' => 1, 'network_error' => true, 'message' => $e->getMessage()];
        }

        // 3. 网络失败：宽限期机制
        if (!empty($result['network_error'])) {
            $failCount = (int)($state['fail_count'] ?? 0) + 1;
            $graceDays = (int)config('store.heartbeat.grace_days', 60);
            $firstFailAt = (int)($state['first_fail_time'] ?? 0) ?: time();
            $overGrace = (time() - $firstFailAt) > $graceDays * 86400;

            if ($overGrace) {
                self::lockPlugin($plugin, self::LICENSE_INVALID, '连续 ' . $graceDays . ' 天无法连接应用插件验证授权，插件已锁定');
                return ['valid' => false, 'status' => self::LICENSE_INVALID, 'message' => '超出离线宽限期'];
            }

            self::updateLicenseState($plugin, [
                'fail_count' => $failCount,
                'first_fail_time' => $firstFailAt,
                'last_check_time' => time(),
                'last_message' => '市场连接失败（宽限期内放行）：' . (string)($result['message'] ?? ''),
            ]);
            return ['valid' => true, 'status' => self::LICENSE_VALID, 'message' => '在线校验失败，宽限期内放行'];
        }

        // 4. 在线校验结果处理
        if (empty($result['valid'])) {
            $status = !empty($result['revoked']) ? self::LICENSE_REVOKED : (int)($result['status'] ?? self::LICENSE_INVALID);
            $message = (string)($result['message'] ?? '授权校验未通过');
            self::lockPlugin($plugin, $status, $message);
            return ['valid' => false, 'status' => $status, 'message' => $message];
        }

        self::updateLicenseState($plugin, [
            'status' => self::LICENSE_VALID,
            'last_check_time' => time(),
            'fail_count' => 0,
            'first_fail_time' => 0,
            'expires_at' => (int)($result['expires_at'] ?? 0) ?: (int)($state['expires_at'] ?? 0),
            'last_message' => (string)($result['message'] ?? '授权有效'),
        ]);

        return ['valid' => true, 'status' => self::LICENSE_VALID, 'message' => (string)($result['message'] ?? '授权有效')];
    }

    /** 手动刷新单个插件授权（后台"刷新授权"按钮） */
    public static function refreshLicense(int $pluginId): array
    {
        $plugin = Plugin::findOrEmpty($pluginId);
        if ($plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        if (!self::isLicenseRequired($plugin)) {
            throw new ApiException('该插件为免费应用，无需授权校验');
        }
        return self::heartbeat($plugin);
    }

    /** 锁定插件（授权失效/吊销/超宽限期） */
    protected static function lockPlugin(Plugin $plugin, int $licenseStatus, string $message): void
    {
        self::updateLicenseState($plugin, [
            'status' => $licenseStatus,
            'last_check_time' => time(),
            'last_message' => $message,
        ]);

        if ((int)$plugin->status === 1) {
            $plugin->save([
                'status' => -1,
                'error_message' => mb_substr('授权异常：' . $message, 0, 480),
            ]);
            PluginManager::clearRuntimeCaches();
        }
    }

    // ─────────────────────── 市场配置读写（system_config，app_name=market） ───────────────────────

    protected static function getMarketConfig(string $code): ?string
    {
        // 显式排除软删除的记录，确保状态正确
        $row = Config::where('app_name', self::CONFIG_APP)
            ->where('code', $code)
            ->whereNull('delete_time')
            ->find();
        $value = $row->value ?? null;
        return $value === null || $value === '' ? null : (string)$value;
    }

    protected static function setMarketConfig(string $code, string $value): void
    {
        // 先查找未删除的记录（显式排除软删除）
        $row = Config::where('app_name', self::CONFIG_APP)
            ->where('code', $code)
            ->whereNull('delete_time')
            ->find();
        
        if ($row) {
            // 已有记录，强制更新值
            if ((string)$row->value !== $value) {
                $row->save(['value' => $value]);
            }
            return;
        }
        
        // 查找已软删除的记录（包含已删除的）
        $deletedRow = Config::where('app_name', self::CONFIG_APP)
            ->where('code', $code)
            ->withTrashed()
            ->find();
        
        if ($deletedRow) {
            // 恢复软删除的记录并更新值
            $deletedRow->restore();
            $deletedRow->save(['value' => $value]);
        } else {
            // 完全不存在则创建新记录
            Config::create([
                'pid' => 0,
                'name' => $code,
                'value' => $value,
                'form_type' => 'text',
                'is_sys' => 1,
                'is_edit' => 0,
                'app_name' => self::CONFIG_APP,
                'code' => $code,
                'sort' => 0,
                'status' => 1,
            ]);
        }
    }

    protected static function deleteMarketConfig(string $code): void
    {
        // 查找记录（包含软删除的）并强制删除
        $row = Config::where('app_name', self::CONFIG_APP)
            ->where('code', $code)
            ->withTrashed()
            ->find();
        
        if ($row) {
            $row->force(true)->delete();
        }
    }
}
