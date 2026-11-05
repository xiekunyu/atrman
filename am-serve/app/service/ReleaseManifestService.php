<?php

namespace app\service;

use app\exception\ApiException;
use app\utils\Directory;
use app\utils\Security;
use support\Log;
use support\Redis;

/**
 * 发布清单（站点侧）：全站唯一的安装包完整性判定入口。
 *
 * 清单由市场签发（一份清单列出某 plugin 全部已发布包的 sha256），站点只做三件事：
 * 验清单签名 → 按 (version, package_type, delta_from) 找条目 → 比对本地包哈希。
 * 包字节不参与签名，因此站点侧无需解压、市场侧无需重压缩。
 *
 * 判定一律硬拒不降级：这里错放行的代价是装上被篡改的包，与缓存类组件「故障即放行」相反。
 * 唯一放行场景是「市场不可达 + 本地缓存清单覆盖该版本且仍在有效期内」，即离线安装。
 *
 * 启用口径由 config/store.php release_manifest.mode 控制：
 * strict（默认，以清单为准）/ shadow（清单校验降级为纯日志，放行只剩票面与落盘哈希兜底）/ off（完整性校验整体关闭）。
 * 包内签验签已在本次重构中整体删除，后两档**没有**「退回旧验签」这层兜底，只作短时排障用。
 * 它们也不是过渡期双轨——产品未正式上线，迁移按「硬断」执行。
 */
class ReleaseManifestService
{
    /** 清单结构版本：与市场侧 am_store ReleaseManifestService::SCHEMA 一致，未知值一律拒绝 */
    public const SCHEMA = 1;

    /** 签发方标识（Mock 提供者沿用同一值；验签不校验它，签名本身已覆盖该字段） */
    public const ISSUER = 'atrman-market';

    public const MODE_OFF = 'off';
    public const MODE_SHADOW = 'shadow';
    public const MODE_STRICT = 'strict';

    /** 已见最大 revision 的 Redis 键前缀（全站键统一带 atrman: 前缀） */
    private const MAX_REV_KEY = 'atrman:release:maxrev:';

    /** revision 键留存 90 天：远长于清单有效期，避免键过期后失去防降级基准 */
    private const MAX_REV_TTL = 7776000;

    /** 当前启用模式 */
    public static function mode(): string
    {
        $mode = strtolower((string)config('store.release_manifest.mode', self::MODE_STRICT));
        return in_array($mode, [self::MODE_OFF, self::MODE_SHADOW, self::MODE_STRICT], true)
            ? $mode
            : self::MODE_STRICT;
    }

    /**
     * 校验安装包是否被官方发布清单覆盖（唯一验签入口）。
     *
     * 条目缺失或哈希不符时会先强制同步一次清单（本地缓存可能落后于市场一次发布），仍不通过才拒。
     *
     * @param string $zipPath 已落盘的安装包绝对路径
     * @param string $plugin 应用标识（主程序为 CoreUpgradeService::CORE_NAME）
     * @param int $packageType 0 全量 / 1 增量
     * @param string $deltaFrom 增量包基准版本（package_type=1 必填）
     * @throws ApiException 清单不可用 / 版本不在清单内 / 哈希不一致
     */
    public static function assertTrusted(string $zipPath, string $plugin, string $version, int $packageType, string $deltaFrom = ''): void
    {
        if (!is_file($zipPath)) {
            throw new ApiException('安装包文件不存在，无法校验完整性');
        }

        $manifest = self::get($plugin);
        if ($manifest === null) {
            throw new ApiException('发布清单不可用（市场不可达或该应用未在市场登记，且本地无有效缓存），无法确认安装包完整性');
        }

        $actual = strtolower(Security::fileSha256($zipPath));
        $entry = self::entryOf($manifest, $version, $packageType, $deltaFrom);
        if ($entry !== null && hash_equals((string)$entry['sha256'], $actual)) {
            return;
        }

        // 条目缺失或哈希不符时先强制同步一次再判：缓存里的清单可能只是落后市场一次发布，
        // 而 get() 见到「签名有效且未过期」的缓存不会重拉——少了这一步，
        // 「市场刚发的新版本」最长要等本地清单过期（7 天）才装得上，开发态重建演示包也会卡死。
        // 这里带 since_revision：手里这份清单可用，缺的只是条目，市场没变化时不必重发全文。
        // 离线时 sync 返回 null，落回原来的报错，判定矩阵语义不变。
        $entry = self::entryOf(self::sync($plugin, self::maxRevision($plugin)) ?? $manifest, $version, $packageType, $deltaFrom);
        if ($entry === null) {
            throw new ApiException('该版本不在官方发布清单内（可能已下架或被撤销）：'
                . $plugin . ' ' . $version . ' package_type=' . $packageType);
        }

        if (!hash_equals((string)$entry['sha256'], $actual)) {
            throw new ApiException('安装包完整性校验失败（与发布清单记录的哈希不一致，包可能已被篡改）');
        }
    }

    /**
     * 安装/升级链的完整性判定入口：按 mode 决定清单校验是「作为判定源」还是「只记差异」。
     *
     * shadow 下任何异常都只落日志（P0 验收要求差异日志为零），不参与判定——注意此时完整性
     * 只剩票面/落盘 sha256 的传输层校验，包内签那条链已经删除，不存在另一层兜底；
     * strict 下异常原样抛出，由调用链中止安装/升级。
     */
    public static function check(string $zipPath, string $plugin, string $version, int $packageType, string $deltaFrom = ''): void
    {
        $mode = self::mode();
        if ($mode === self::MODE_OFF) {
            return;
        }
        try {
            self::assertTrusted($zipPath, $plugin, $version, $packageType, $deltaFrom);
        } catch (\Throwable $e) {
            if ($mode === self::MODE_STRICT) {
                throw $e instanceof ApiException ? $e : new ApiException('发布清单校验失败：' . $e->getMessage());
            }
            Log::error('[发布清单影子校验] ' . $plugin . ' ' . $version
                . '（package_type=' . $packageType . '）与清单不一致：' . $e->getMessage());
        }
    }

    /**
     * 取可用清单：缓存可直接用则直出，否则重拉一次；返回前一律重验签名与陈旧期。
     * @return array|null 清单结构；无缓存且市场不可达时 null
     */
    public static function get(string $plugin): ?array
    {
        $cached = self::readCache($plugin);
        if ($cached !== null && self::isUsable($cached, $plugin)) {
            return $cached;
        }

        // 缓存缺失、已过期、或验不过（市场密钥轮换、provider 在 mock/remote 间切换、文件损坏）都重拉，
        // 且不带 since_revision（见 sync() 参数说明：带着它换来的一句「无变化」会让过期缓存继续卡死判定）。
        // sync 内部已带防降级基准校验并落缓存，不再对同一份清单重复验签；
        // 拉不到时把缓存交给 verifyManifest，由它给出确切原因（过期 / 非官方签发）
        $manifest = self::sync($plugin, 0);
        if ($manifest !== null) {
            return $manifest;
        }
        if ($cached === null) {
            return null;
        }
        self::verifyManifest($cached, $plugin, self::maxRevision($plugin));
        return $cached;
    }

    /** 缓存副本能否免于重拉（失败不抛，只触发 sync）；基准与 sync 路径同源，别让回滚过的旧缓存免检通过 */
    private static function isUsable(array $manifest, string $plugin): bool
    {
        try {
            self::verifyManifest($manifest, $plugin, self::maxRevision($plugin));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 从市场拉取清单并落缓存。
     *
     * @param int $sinceRevision 本地已见 revision，>0 时市场条目未变只回 `{unchanged:true}` 省流量。
     *                           只有「手里的清单本身还能用、缺的只是条目」时才带它：缓存缺失或已过期时带旧值，
     *                           市场按**市场自己的时钟**判「这行还没过期、不必重签」→ 回 unchanged →
     *                           站点既拿不到新清单、又用不了手里这份，一直锁到市场下次重签（最长 7 天）；
     *                           站点时钟快于市场时正是这个死窗口。
     * @return array|null 新清单；市场告知「无变化」时返回 null（沿用缓存）
     */
    public static function sync(string $plugin, int $sinceRevision = 0): ?array
    {
        try {
            $manifest = StoreService::getProvider()->releaseManifest($plugin, $sinceRevision);
        } catch (\Throwable $e) {
            Log::warning('[发布清单] 同步失败（' . $plugin . '）：' . $e->getMessage());
            return null;
        }
        if ($manifest === null) {
            // 市场回「无变化」：手里这份清单仍是当前清单，本次不覆盖基准也不重签
            return null;
        }

        self::verifyManifest($manifest, $plugin, self::maxRevision($plugin));
        self::writeCache($plugin, $manifest);
        self::rememberRevision($plugin, (int)$manifest['revision']);
        return $manifest;
    }

    /**
     * 按 (version, package_type, delta_from) 定位条目。
     *
     * 版本号用 version_compare 而非字符串相等：它按段做数字比较，能接上「1.01 ≡ 1.1」这类前导零写法。
     * 但它**接不上尾零**（`version_compare('1.1.0', '1.1')` 返回 1），所以市场行的 version 与包内
     * manifest.version 必须逐字一致——登记（`registerFullPackage` 逐字 trim 比对）与审核链正是这么把关的，
     * 这里不额外做规范化，免得清单匹配比登记更宽松。
     */
    public static function entryOf(array $manifest, string $version, int $packageType, string $deltaFrom = ''): ?array
    {
        foreach ((array)($manifest['entries'] ?? []) as $entry) {
            if ((int)($entry['package_type'] ?? -1) !== $packageType) {
                continue;
            }
            if (version_compare((string)($entry['version'] ?? ''), $version, '!=')) {
                continue;
            }
            if ($packageType === 1 && version_compare((string)($entry['delta_from'] ?? ''), $deltaFrom, '!=')) {
                continue;
            }
            return $entry;
        }
        return null;
    }

    // ─────────────────────── 内部实现 ───────────────────────

    /**
     * 清单自身可信性校验：结构 → 条目聚合哈希 → 签名 → 防降级。
     *
     * @param int $knownRevision 本地已见最大 revision，0 表示无基准（首次同步）不判回退
     */
    private static function verifyManifest(array $manifest, string $plugin, int $knownRevision = 0): void
    {
        if ((int)($manifest['schema'] ?? 0) !== self::SCHEMA) {
            throw new ApiException('发布清单格式版本不受支持（schema=' . (string)($manifest['schema'] ?? '') . '）');
        }
        if ((string)($manifest['plugin'] ?? '') !== $plugin) {
            throw new ApiException('发布清单与应用标识不符（清单=' . (string)($manifest['plugin'] ?? '') . '，请求=' . $plugin . '）');
        }

        $entries = (array)($manifest['entries'] ?? []);
        $entriesHash = Security::releaseEntriesHash($entries);
        if (!hash_equals(strtolower((string)($manifest['entries_hash'] ?? '')), $entriesHash)) {
            throw new ApiException('发布清单条目哈希不匹配（清单已被篡改）');
        }

        $signature = (string)($manifest['signature'] ?? '');
        if ($signature === '') {
            throw new ApiException('发布清单缺少签名');
        }
        // 载荷按 8 个标量字段逐个重建，不直接复用应答数组：多一个字段就会让两端规范化结果不同
        $payload = [
            'schema' => (int)($manifest['schema'] ?? 0),
            'issuer' => (string)($manifest['issuer'] ?? ''),
            'plugin' => (string)($manifest['plugin'] ?? ''),
            'revision' => (int)($manifest['revision'] ?? 0),
            'issued_at' => (int)($manifest['issued_at'] ?? 0),
            'expires_at' => (int)($manifest['expires_at'] ?? 0),
            'entries_hash' => strtolower((string)($manifest['entries_hash'] ?? '')),
            'alg' => (string)($manifest['alg'] ?? ''),
        ];
        $keyInfo = StoreService::getProvider()->signingPublicKey();
        $alg = $payload['alg'] !== '' ? $payload['alg'] : (string)($keyInfo['alg'] ?? Security::ALG_ED25519);
        if (!Security::verify(Security::canonicalPayload($payload), $signature, (string)$keyInfo['public_key'], $alg)) {
            throw new ApiException('发布清单签名验证失败（非官方签发）');
        }

        $revision = (int)($manifest['revision'] ?? 0);
        if ($revision <= 0) {
            throw new ApiException('发布清单 revision 非法');
        }
        if ($knownRevision > 0 && $revision < $knownRevision) {
            throw new ApiException('发布清单版本回退（本地 #' . $knownRevision . '，应答 #' . $revision . '）');
        }

        // 有效期是唯一的离线边界（市场侧默认签 7 天）：过期即须重新联网同步，
        // 不设额外陈旧宽限，避免「撤销多久才生效」变成两个可调参数的乘积
        if ((int)($manifest['expires_at'] ?? 0) <= time()) {
            throw new ApiException('发布清单已过期，无法确认安装包完整性（请联网重新同步）');
        }
    }

    /** 缓存文件路径（plugin 先过标识白名单，杜绝拼路径穿越） */
    private static function cachePath(string $plugin): string
    {
        self::assertPluginName($plugin);
        return runtime_path() . '/releases/' . $plugin . '.json';
    }

    /**
     * 应用标识白名单校验。
     * 标识会拼进缓存文件路径与 Redis 键，非法字符必须在触盘前拒掉，
     * 且要显式报错而不是静默降级成「无清单」（后者会让 strict 模式给出误导性结论）。
     */
    private static function assertPluginName(string $plugin): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $plugin)) {
            throw new ApiException('应用标识非法，无法读写发布清单：' . $plugin);
        }
    }

    private static function readCache(string $plugin): ?array
    {
        $path = self::cachePath($plugin);
        if (!is_file($path)) {
            return null;
        }
        $manifest = json_decode((string)file_get_contents($path), true);
        return is_array($manifest) && !empty($manifest['signature']) ? $manifest : null;
    }

    private static function writeCache(string $plugin, array $manifest): void
    {
        $path = self::cachePath($plugin);
        Directory::ensureDir(dirname($path));
        $written = @file_put_contents($path, (string)json_encode($manifest, JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            // 缓存写不下不影响本次判定（清单已在内存），但离线安装能力会缺失，留痕便于排查
            Log::warning('[发布清单] 缓存写入失败（' . $plugin . '）：' . $path);
        }
    }

    /**
     * 已见最大 revision（防降级基准）。
     * Redis 不可用时退回缓存清单里的 revision：基准可以变弱，但绝不能变成「无基准即放行」。
     */
    private static function maxRevision(string $plugin): int
    {
        try {
            $value = Redis::get(self::MAX_REV_KEY . $plugin);
            if ($value !== null && $value !== false && (int)$value > 0) {
                return (int)$value;
            }
        } catch (\Throwable $e) {
            Log::warning('[发布清单] revision 基准读取失败，改用本地缓存基准：' . $e->getMessage());
        }
        $manifest = self::readCache($plugin);
        return $manifest === null ? 0 : (int)($manifest['revision'] ?? 0);
    }

    private static function rememberRevision(string $plugin, int $revision): void
    {
        try {
            Redis::set(self::MAX_REV_KEY . $plugin, $revision, 'EX', self::MAX_REV_TTL);
        } catch (\Throwable $e) {
            // 写不进 Redis 时缓存文件仍带 revision，降级基准可用，只记日志
            Log::warning('[发布清单] revision 基准写入失败：' . $e->getMessage());
        }
    }
}
