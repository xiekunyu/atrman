<?php

namespace app\service;

use app\exception\ApiException;
use app\model\system\Migration;
use app\utils\Directory;
use app\utils\Security;
use support\Log;
use support\Redis;

/**
 * 主程序（基座）升级执行服务
 *
 * 只做"编排 + 主程序作用域适配"，下载/校验/解压/备份/迁移/composer/重载均复用
 * StoreService / FileService / BackupService / DatabaseService / PluginComposerService。
 *
 * 铁律：
 * - 主程序升级只走增量包（全量包含 vendor/config/composer.*，覆盖已装实例会毁掉用户改动）；
 *   链断由服务端选包阶段直接报错，客户端不做任何全量回退。
 * - 每个待装包先经 ticket 换直链 + sha256 强校验，再参与覆盖/叠加；校验值缺失一律拒绝执行。
 * - 自动回滚依据「覆盖前逐文件镜像的 pre 快照」：本次改过什么就精确还原什么。
 *   整站备份（BackupService::backupSystem 不含 app/）仍会创建，但只作异常后人工兜底，绝不自动整库还原——
 *   那会丢弃升级窗口内的业务写入，且跨主版本恢复会被兼容性校验拒绝。
 * - composer 依赖在升级任务内同步执行，沿用全局串行锁与白名单过滤。
 * - 执行期间置全局维护标记，配合 SystemMaintenance 中间件拦截非白名单请求。
 * - 队列任务非幂等：收尾/清理过程的异常一律内部吞掉，绝不外逸（外逸会被 PluginJobConsumer 重投重跑升级）。
 *
 * 两种执行模式（结果必须一致）：
 * - sequential：沿增量链逐包"下载→校验→覆盖差异文件→迁移→composer"；
 * - merged：先把全部增量包按版本升序解压叠加到一个临时目录，再统一覆盖 + 合并迁移 +
 *   合并 composer 一次执行（推荐，重启/依赖安装次数最少）。
 */
class CoreUpgradeService
{
    /** 队列 handler 名（基座在 PluginBootstrapper 注册） */
    public const HANDLER = 'base_core_upgrade';

    /** 主程序在迁移记录中的归属标识 */
    public const CORE_NAME = 'atrman';

    /** 任务快照 key 前缀（string，json） */
    private const SNAP_PREFIX = 'atrman:core:upgrade:job:';

    /** 任务日志行 key 前缀（list） */
    private const LOG_PREFIX = 'atrman:core:upgrade:log:';

    /** 升级串行锁 key（同一时刻只允许一个升级任务） */
    private const LOCK_KEY = 'atrman:core:upgrade:lock';

    /** 全局维护标记 key（SystemMaintenance 中间件读取） */
    public const MAINT_KEY = 'atrman:core:upgrade:maintenance';

    /** 任务/日志快照 TTL（秒） */
    private const TTL = 3600;

    /** 锁 TTL（秒），需覆盖备份 + 多包 + composer 最长耗时；任务运行中由 emitLine 心跳续期 */
    private const LOCK_TTL = 3600;

    /**
     * 维护标记兜底 TTL（秒）：与锁 TTL 对齐。
     * 取值要保守——任务进程若在重载时被强杀而未摘掉标记，这就是站点挂维护页的最长时间；
     * 正常路径下收尾（含 exitMaintenance）已前置于重载之前，另有 isMaintaining() 的残留自愈兜底。
     */
    private const MAINT_TTL = 3600;

    /**
     * 维护标记「人工钉住」TTL（秒，30 天）：回滚不彻底时把站点扣在维护模式里，
     * 直到人工处理完（重新升级成功会走 exitMaintenance 摘除）；半升级状态绝不因锁到期或自愈而自动对外。
     */
    private const HOLD_TTL = 2592000;

    /** 文件级回滚快照目录（相对任务工作目录）与索引文件名 */
    private const PRE_DIR = 'pre';

    private const PRE_INDEX = 'index.json';

    /** 本次改过、需在回滚时撤销记录的迁移文件清单 */
    private const APPLIED_FILE = 'applied_migrations.json';

    /** 升级配置中本版尚未实现的键（出现即显式提示操作者，绝不静默丢弃） */
    private const UNSUPPORTED_CONFIG = ['pre_hook', 'post_hook', 'env_require'];

    /** 当前进程持有的锁令牌（释放/续期时比对，避免超时后误操作他人锁） */
    private static string $lockToken = '';

    /** 最近一次锁续期时间戳（心跳节流用） */
    private static int $lastTouch = 0;

    /** 日志行保留上限 */
    private const LOG_CAP = 500;

    /** 临时工作目录根 */
    private const WORK_ROOT = 'runtime/core_upgrade';

    /** 差异文件黑名单（一级目录/文件名）：环境与用户数据永不允许被包覆盖 */
    private const DENY_TOP = ['.env', 'runtime', 'node_modules'];

    /** 差异文件黑名单前缀：上传/存储目录 */
    private const DENY_PREFIX = ['public/storage'];

    // ───────────────────────────── 计划（HTTP 侧） ─────────────────────────────

    /**
     * 升级计划展示入口（HTTP 侧）：对“已是最新 / 链不完整 / 未开升级”这些正常业务态
     * 不报异常，而是带 mode + message 返回，由升级页内联告知；否则每次打开页面都会弹红色错误。
     */
    public static function plan(): array
    {
        try {
            return self::buildPlan();
        } catch (ApiException $e) {
            return self::planNotice($e->getMessage());
        }
    }

    /** 不可升级计划的降级返回（字段齐全，前端无需分支判空）。 */
    protected static function planNotice(string $message): array
    {
        $current = '';
        try {
            $current = (string)PluginManifestService::getAppVersion();
        } catch (\Throwable $e) {
            // 版本读不到不影响“当前不可升级”这一结论
        }
        return [
            'has_update' => false,
            'current_version' => $current,
            'latest_version' => '',
            'mode' => 'error',
            'message' => $message,
            'strategy' => 'merged',
            'packages' => [],
            'summary' => [
                'package_count' => 0,
                'composer_requires' => new \stdClass(),
                'migrations' => [],
                'changed_files' => [],
                'changed_count' => 0,
                'changed_declared' => true,
                'need_restart' => false,
                'size_total' => 0,
                'unsupported_config' => [],
                'changelog' => [],
            ],
            'env' => PluginComposerService::probeEnv([]),
        ];
    }

    /**
     * 严格生成执行计划（不下载、不改任何文件）；不可升级的原因一律抛出。
     * 展示层调 plan()（不抛），execute() 调本方法（拦住任何不能执行的升级）。
     *
     * @return array{current_version,latest_version,mode,strategy,packages,summary,env}
     */
    protected static function buildPlan(): array
    {
        // 先判开关再判 has_update，否则关闭时会被说成「已是最新」，掩盖升级入口被自己关了
        if (!StoreService::systemUpdateEnabled()) {
            throw new ApiException('主程序更新检测已关闭（.env 的 STORE_SYSTEM_UPDATE=false），在线升级不可用');
        }
        $info = StoreService::checkSystemUpdate();
        if (empty($info['has_update'])) {
            throw new ApiException('当前已是最新版本，无需升级');
        }

        $upgrade = (array)($info['upgrade'] ?? []);
        $mode = (string)($upgrade['mode'] ?? 'none');
        if ($mode === 'error') {
            throw new ApiException((string)($upgrade['message'] ?? '升级链不完整，暂无法升级'));
        }
        if ($mode === 'full') {
            // 已装实例绝不接受全量覆盖（全量包仅供全新安装）
            throw new ApiException('主程序升级仅支持增量包，请联系官方补发当前版本的增量包');
        }

        $packages = self::sortPackages((array)($upgrade['packages'] ?? []));
        if (!$packages) {
            throw new ApiException('没有可用的升级包');
        }

        // 铁律前置：每个包都必须带有效 sha256，缺值直接不给执行（不拖到队列里才失败）
        foreach ($packages as $package) {
            $sha = strtolower((string)($package['sha256'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
                throw new ApiException('版本 ' . (string)($package['version'] ?? '') . ' 缺少有效的 sha256 校验值，无法安全升级，请联系发布方补全');
            }
        }

        // 基准版本必须读到，读不到直接中止，避免以空基准选包
        $current = trim((string)($info['current_version'] ?? ''));
        if ($current === '') {
            $current = PluginManifestService::requireAppVersion();
        }
        self::assertChainContinuity($packages, $current);

        [$composer, $migrations, $changed, $needRestart, $sizeTotal, $unsupported, $changedDeclared] = self::mergeMeta($packages);

        return [
            'has_update' => true,
            'current_version' => $current,
            'latest_version' => (string)($info['latest_version'] ?? ($packages[array_key_last($packages)]['version'] ?? '')),
            'mode' => $mode,
            'strategy' => 'merged',
            'packages' => $packages,
            'summary' => [
                'package_count' => count($packages),
                'composer_requires' => $composer ?: new \stdClass(),
                'migrations' => $migrations,
                'changed_files' => $changed,
                'changed_count' => count($changed),
                // 包未声明差异清单时执行会覆盖包内全部文件，计划里不能把这个数量隐为 0
                'changed_declared' => $changedDeclared,
                'need_restart' => $needRestart,
                'size_total' => $sizeTotal,
                // 配置里填了但本版尚未实现的字段，提前告知操作者（不静默忽略）
                'unsupported_config' => $unsupported,
                'changelog' => array_values(array_filter(array_map(
                    static fn(array $p): string => (string)($p['changelog'] ?? ''),
                    $packages
                ))),
            ],
            'env' => PluginComposerService::probeEnv($composer),
        ];
    }

    /**
     * 校验环境 + 建锁 + 投递升级任务（实际执行在队列消费进程内）。
     *
     * @param string $strategy sequential|merged
     * @return array{job_id, channel, package_count}
     */
    public static function execute(string $strategy = 'merged', int $operatorId = 0): array
    {
        if (!in_array($strategy, ['sequential', 'merged'], true)) {
            throw new ApiException('升级策略只能是 sequential 或 merged');
        }

        $plan = self::buildPlan();
        $packages = self::jsonSafe($plan['packages']);

        // 全局串行锁：主程序覆盖不可并发；锁值存本次任务令牌，释放/续期都按令牌比对，
        // 防止长任务锁过期后被新任务取得、又被本任务 finally 误删
        $lockToken = bin2hex(random_bytes(8));
        $locked = Redis::set(self::LOCK_KEY, $lockToken, 'EX', self::LOCK_TTL, 'NX');
        if (!$locked) {
            throw new ApiException('已有主程序升级任务正在进行，请等待其结束后再试');
        }

        $jobId = bin2hex(random_bytes(8));
        self::writeSnapshot($jobId, [
            'job_id' => $jobId,
            'strategy' => $strategy,
            'status' => 'queued',
            'phase' => 'queued',
            'current_version' => $plan['current_version'],
            'target_version' => $plan['latest_version'],
            'package_count' => count($packages),
            'backup_id' => null,
            'message' => '升级任务已排队，等待队列消费进程执行',
            'operator_id' => $operatorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $dispatched = QueueService::dispatch(self::HANDLER, [
            'job_id' => $jobId,
            'strategy' => $strategy,
            'packages' => $packages,
            'current_version' => $plan['current_version'],
            'target_version' => $plan['latest_version'],
            'operator_id' => $operatorId,
            'lock_token' => $lockToken,
        ]);
        if (!$dispatched) {
            self::releaseLock($lockToken);
            Redis::del(self::SNAP_PREFIX . $jobId);
            throw new ApiException('队列不可用，无法执行主程序升级，请检查队列服务后手动升级');
        }

        Log::info('[CoreUpgrade] 已投递主程序升级任务 job=' . $jobId
            . ' strategy=' . $strategy
            . ' ' . $plan['current_version'] . ' → ' . $plan['latest_version']
            . ' operator=' . $operatorId);

        return [
            'job_id' => $jobId,
            'channel' => self::channel($jobId),
            'package_count' => count($packages),
        ];
    }

    /** 读任务快照 + 历史日志（供订阅回填 / push 不可用时降级轮询）。 */
    public static function status(string $jobId): ?array
    {
        $snapRaw = Redis::get(self::SNAP_PREFIX . $jobId);
        if (!$snapRaw) {
            return null;
        }
        $snap = json_decode($snapRaw, true) ?: [];
        $snap['log'] = Redis::lRange(self::LOG_PREFIX . $jobId, 0, -1) ?: [];
        // 累计写入行数（可能大于 log 数组长度，前端据此按序号只追加新行并知道被裁掉了多少）
        $snap['log_total'] = (int)Redis::get(self::LOG_PREFIX . $jobId . ':total');
        $snap['maintaining'] = self::isMaintaining();
        return $snap;
    }

    /**
     * 是否处于升级维护模式（带残留自愈）。
     *
     * 维护标记通常只在升级任务持有串行锁期间有意义：标记存在而锁已不存在，
     * 说明上次任务被强杀（如重载重启）没来得及摘标记，此时立即清理放行，
     * 避免站点长时间挂着维护页。
     *
     * 例外：payload 带 hold 的标记是任务在「文件未完全还原」时钉住的安全栏，
     * 不参与自愈，只能由人工处置后的新一轮升级成功（或手动清 key）解除。
     */
    public static function isMaintaining(): bool
    {
        try {
            $raw = (string)Redis::get(self::MAINT_KEY);
            if ($raw === '') {
                return false;
            }
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['hold'])) {
                return true;
            }
            if (Redis::exists(self::LOCK_KEY)) {
                return true;
            }
            Redis::del(self::MAINT_KEY);
            Log::warning('[CoreUpgrade] 检测到无升级锁的残留维护标记，已自动解除');
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 维护模式详情（供中间件与前端展示）。 */
    public static function maintenanceInfo(): array
    {
        try {
            $raw = (string)Redis::get(self::MAINT_KEY);
        } catch (\Throwable $e) {
            return [];
        }
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ───────────────────────────── 队列执行体 ─────────────────────────────

    /**
     * 队列 handler 主体：维护 → 整站备份（人工兜底） → 逐文件建快照后覆盖 → 迁移 → composer
     * → 版本落库 → 收尾（解除维护 + 落成功快照） → 重载；失败按 pre 快照做文件级回滚。
     *
     * 两个关键顺序约束：
     * 1. 收尾必须前置于 gracefulReload —— 重载会重启队列消费进程，本函数可能在 reload 之后就被终止；
     * 2. 全函数绝不向外抛异常 —— 升级任务非幂等，异常外逸会被 PluginJobConsumer 交给队列重投重跑。
     */
    public static function run(array $payload): void
    {
        $jobId = (string)($payload['job_id'] ?? '');
        $strategy = (string)($payload['strategy'] ?? 'merged');
        $packages = self::jsonSafe((array)($payload['packages'] ?? []));
        if ($jobId === '' || !$packages) {
            return;
        }

        $channel = self::channel($jobId);
        $workDir = runtime_path() . '/core_upgrade/' . $jobId;
        $preDir = $workDir . '/' . self::PRE_DIR;
        self::$lockToken = (string)($payload['lock_token'] ?? '');
        self::$lastTouch = time();
        $backupId = 0;
        $rollbackOk = false;
        $error = null;

        try {
            self::updateSnapshot($jobId, ['status' => 'running', 'phase' => 'prepare', 'message' => '开始执行主程序升级']);
            self::push($channel, 'status', ['status' => 'running', 'phase' => 'prepare']);

            $current = trim((string)($payload['current_version'] ?? ''));
            if ($current === '') {
                $current = PluginManifestService::requireAppVersion();
            }
            $packages = self::sortPackages($packages);
            self::assertChainContinuity($packages, $current);
            $target = (string)$packages[count($packages) - 1]['version'];

            // 1. 进入维护模式（升级期间站点暂停访问）
            self::enterMaintenance($jobId, $current, $target);
            self::emitLine('已进入维护模式，站点访问暂停（完成后自动解除）。', $channel, $jobId);

            Directory::ensureDir($workDir);

            // 2. 升级前整站备份：自动回滚不依赖它（其清单不含 app/），它只用于极端情况下人工恢复；
            //    备份建不起来就中止，避免连兜底都没有就开始改文件
            self::updateSnapshot($jobId, ['phase' => 'backup', 'message' => '正在创建升级前整站备份']);
            self::emitLine('正在创建升级前整站备份（含数据库导出），仅作异常时的人工兜底，请耐心等待…', $channel, $jobId);
            $backup = BackupService::backupSystem();
            $backupId = (int)$backup->id;
            self::updateSnapshot($jobId, ['backup_id' => $backupId]);
            self::emitLine('备份完成：备份记录 #' . $backupId . '（版本 ' . (string)$backup->version . '）', $channel, $jobId);

            // 3. 按模式执行（覆盖基座前逐文件建立 pre 快照，任一步失败可精确还原）
            self::updateSnapshot($jobId, ['phase' => 'apply', 'message' => '正在应用升级包']);
            $needRestart = $strategy === 'merged'
                ? self::runMerged($packages, $current, $target, $jobId, $channel, $workDir)
                : self::runSequential($packages, $current, $jobId, $channel, $workDir);

            // 4. 版本落库（manifest.json 唯一写入点，顺带清理 .env 残键）
            self::updateSnapshot($jobId, ['phase' => 'version', 'message' => '正在写入版本号']);
            self::persistVersion($target, $jobId, $channel, $preDir);

            // 5. 收尾前置：先解除维护并落定成功状态，再触发重载
            self::exitMaintenance();
            self::updateSnapshot($jobId, [
                'status' => 'success',
                'phase' => 'done',
                'target_version' => $target,
                'need_restart' => $needRestart,
                'message' => '主程序已升级到 ' . $target . ($needRestart ? '，正在重载服务生效' : '，本次无需重启'),
            ]);
            self::push($channel, 'done', [
                'status' => 'success',
                'target_version' => $target,
                'need_restart' => $needRestart,
            ]);
            self::emitLine('升级完成：' . $current . ' → ' . $target, $channel, $jobId);
            Log::info('[CoreUpgrade] 升级成功 job=' . $jobId . ' ' . $current . ' → ' . $target);

            // 6. 重载前先释放串行锁：信号发出后本进程可能随 worker 重启被终止，
            //    若不提前释放，锁会挂到 TTL 到期，期间后续升级会被误判「任务进行中」
            self::releaseLock();
            self::$lockToken = '';

            // 7. 重载放在最后：此时状态已落定、锁已释放，进程被终止也不影响结果
            if ($needRestart) {
                $reload = PluginComposerService::gracefulReload();
                self::emitLine($reload['message'], $channel, $jobId);
                self::updateSnapshot($jobId, ['reloaded' => (bool)($reload['ok'] ?? false)]);
            }
        } catch (\Throwable $e) {
            $error = $e;
            Log::error('[CoreUpgrade] 升级任务失败 job=' . $jobId . '：' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
            self::emitLine('【失败】' . get_class($e) . ': ' . $e->getMessage(), $channel, $jobId);
            self::emitLine('出处 ' . $e->getFile() . ':' . $e->getLine(), $channel, $jobId);

            // 自动回滚：只还原本次真正改过的文件 + 撤销本次迁移记录。
            // 不自动整库还原：那会丢弃维护窗口内队列与后台写入的数据，且跨主版本会被兼容性校验拒绝。
            try {
                if (is_dir($preDir)) {
                    self::updateSnapshot($jobId, ['phase' => 'rollback', 'message' => '正在按文件级快照回滚']);
                    $result = self::restorePre($preDir, $channel, $jobId);
                    self::revertAppliedMigrations($workDir, $jobId, $channel);
                    $rollbackOk = empty($result['failed']);
                } else {
                    // 尚未改动任何基座文件，直接解除维护
                    $rollbackOk = true;
                    self::emitLine('升级在改动任何文件前中止，无需回滚。', $channel, $jobId);
                }
                if ($rollbackOk) {
                    self::exitMaintenance();
                } else {
                    // 先钉住维护再报“保持维护”：否则 finally 释放锁后自愈会直接把维护标记拆掉，
                    // 站点会带着半升级代码对外服务，日志里的“保持维护模式”就成了假的承诺
                    self::holdMaintenance($jobId, $error->getMessage());
                    $kept = self::preservePre($preDir, $jobId);
                    self::emitLine('【严重】部分文件未能自动还原，站点保持维护模式，'
                        . '请到「备份与恢复」用备份 #' . $backupId . ' 人工恢复后重试。', $channel, $jobId);
                    self::emitLine('文件级回滚镜像已保留在：' . ($kept !== '' ? $kept : $preDir . '（未能迁移，请尽快手动备份）')
                        . '，可用于逐文件人工还原。', $channel, $jobId);
                }
            } catch (\Throwable $e2) {
                Log::error('[CoreUpgrade] 回滚异常 job=' . $jobId . '：' . $e2->getMessage());
                self::holdMaintenance($jobId, $e2->getMessage());
                $kept = self::preservePre($preDir, $jobId);
                self::emitLine('【严重】自动回滚异常：' . $e2->getMessage() . '，站点保持维护模式，'
                    . '请用备份 #' . $backupId . ' 人工恢复。'
                    . ($kept !== '' ? '回滚镜像已保留在：' . $kept : ''), $channel, $jobId);
                $rollbackOk = false;
            }

            self::updateSnapshot($jobId, [
                'status' => 'failed',
                'phase' => $rollbackOk ? 'rolled-back' : 'failed',
                'rolled_back' => $rollbackOk,
                'backup_id' => $backupId,
                'message' => $rollbackOk
                    ? '升级失败，已回滚到升级前状态：' . $error->getMessage()
                    : '升级失败：' . $error->getMessage(),
            ]);
            self::push($channel, 'done', [
                'status' => 'failed',
                'rolled_back' => $rollbackOk,
                'message' => $error->getMessage(),
            ]);
        } finally {
            // 收尾一律吞异常：任何清理失败都不得外逸，否则非幂等的升级任务会被队列重投重跑
            try {
                if (is_dir($workDir)) {
                    Directory::removeDirWithin($workDir, runtime_path() . '/core_upgrade');
                }
            } catch (\Throwable $e) {
                Log::warning('[CoreUpgrade] 清理工作目录失败 job=' . $jobId . '：' . $e->getMessage());
            }
            self::releaseLock();
            self::$lockToken = '';
        }
    }

    // ───────────────────────────── 两种执行模式 ─────────────────────────────

    /**
     * 模式 A：sequential —— 沿增量链按版本升序逐包下载并就地覆盖差异文件。
     * 依赖安装按「链上最高约束」生效（与 merged 模式同口径），低约束声明跳过并日志说明。
     * @return bool 是否需要重启
     */
    protected static function runSequential(array $packages, string $current, string $jobId, string $channel, string $workDir): bool
    {
        $needRestart = false;
        $total = count($packages);
        $preDir = $workDir . '/' . self::PRE_DIR;
        // 已生效的依赖约束（跨包累计），用于把本包中与已装更高约束相左的声明过滤掉
        $applied = [];
        foreach ($packages as $index => $package) {
            $version = (string)$package['version'];
            $step = $index + 1;
            self::updateSnapshot($jobId, [
                'progress' => ['step' => $step, 'total' => $total, 'version' => $version],
                'message' => '正在升级第 ' . $step . '/' . $total . ' 步（' . $version . '）',
            ]);

            $root = self::fetchPackage($package, $jobId, $channel, $workDir, 'pkg_' . $step . '_' . $version);
            $config = self::packageConfig($package, $root);
            $changed = (array)$config['changed_files'];
            self::warnUnsupportedConfig((array)$config['unsupported'], $version, $channel, $jobId);

            self::emitLine('第 ' . $step . '/' . $total . ' 步：覆盖差异文件 ' . count($changed) . ' 个（' . $version . '）', $channel, $jobId);
            self::pruneOrphanAssets($preDir, $changed, $channel, $jobId);
            self::applyFiles($root, $changed, $jobId, $channel, null, $preDir);
            self::syncAdminEntry($preDir, $changed, $channel, $jobId);

            self::runMigrations((array)$config['migrations'], $current, $version, $jobId, $channel, $workDir);

            $requires = self::filterStrongerRequires(self::normalizeRequires($package['composer_requires'] ?? []), $applied);
            foreach ($requires['skipped'] as $pkg => $constraint) {
                self::emitLine('依赖 ' . $pkg . ' 链上已声明更高约束（' . (string)$applied[$pkg] . '），本包声明 ' . $constraint . ' 跳过', $channel, $jobId);
            }
            self::runComposer($requires['install'], $jobId, $channel);

            $needRestart = $needRestart || (bool)$config['need_restart'];
            $current = $version;
        }
        return $needRestart;
    }

    /**
     * 模式 B：merged —— 全部增量包按版本升序解压叠加到临时目录，再统一覆盖 + 合并迁移 + 一次 composer。
     * 依赖合并同样取最高约束，与 sequential 模式结果一致。
     * @return bool 是否需要重启
     */
    protected static function runMerged(array $packages, string $current, string $target, string $jobId, string $channel, string $workDir): bool
    {
        $overlay = $workDir . '/overlay';
        $preDir = $workDir . '/' . self::PRE_DIR;
        $total = count($packages);
        $composer = [];
        $migrations = [];
        $needRestart = false;

        // 1. 逐包下载 + 校验 + 叠加解压（严格按版本升序，后包覆盖前包同名文件）
        foreach ($packages as $index => $package) {
            $version = (string)$package['version'];
            self::updateSnapshot($jobId, [
                'progress' => ['step' => $index + 1, 'total' => $total, 'version' => $version, 'action' => 'stage'],
                'message' => '正在叠加第 ' . ($index + 1) . '/' . $total . ' 个升级包（' . $version . '）',
            ]);
            $root = self::fetchPackage($package, $jobId, $channel, $workDir, 'stage_' . ($index + 1) . '_' . $version);
            $config = self::packageConfig($package, $root);
            self::warnUnsupportedConfig((array)$config['unsupported'], $version, $channel, $jobId);
            // 叠加到临时目录时不存基座快照（尚未触碰基座）
            self::applyFiles($root, (array)$config['changed_files'], $jobId, $channel, $overlay);

            $composer = self::mergeRequires($composer, self::normalizeRequires($package['composer_requires'] ?? []));
            foreach ((array)$config['migrations'] as $migration) {
                $key = (string)$migration;
                if ($key !== '' && !in_array($key, $migrations, true)) {
                    $migrations[] = $key;
                }
            }
            $needRestart = $needRestart || (bool)$config['need_restart'];
        }

        // 2. 叠加目录内的文件清单即最终差异集（同名文件已按版本升序取最后内容）
        $finalFiles = self::collectFiles($overlay);
        self::emitLine('统一覆盖合并差异文件 ' . count($finalFiles) . ' 个', $channel, $jobId);
        // 覆盖前清理构建产物孤儿：本次向 public/assets 投放了文件即视为前端整目录重建（对齐 post-build 的 rm -rf assets）
        self::pruneOrphanAssets($preDir, $finalFiles, $channel, $jobId);
        self::applyFiles($overlay, $finalFiles, $jobId, $channel, null, $preDir);
        // 覆盖后同步自定义后台入口名：把新的 public/index.html 落到 APP_ENTRY_FILE，并移除默认入口避免“复活”
        self::syncAdminEntry($preDir, $finalFiles, $channel, $jobId);

        // 3. 合并迁移一次执行
        self::runMigrations($migrations, $current, $target, $jobId, $channel, $workDir);

        // 4. 合并依赖一次安装
        self::runComposer($composer, $jobId, $channel);

        return $needRestart;
    }

    // ───────────────────────────── 包下载与解包 ─────────────────────────────

    /**
     * 换票 → 下载（sha256 强校验 + 发布清单验签）→ 安全解压，返回包内容根目录。
     * 调用方需保证 $subDir 位于 $workDir 内。
     */
    protected static function fetchPackage(array $package, string $jobId, string $channel, string $workDir, string $subDir): string
    {
        $version = (string)($package['version'] ?? '');
        if ($version === '') {
            throw new ApiException('升级包缺少版本号');
        }
        if ((int)($package['package_type'] ?? 1) !== 1) {
            throw new ApiException('主程序升级只允许增量包（版本 ' . $version . ' 被标记为全量包），已中止');
        }

        $expectSha = strtolower((string)($package['sha256'] ?? ''));
        // 铁律：每步必须先校 sha256 再参与覆盖。校验值缺失/非法直接拒绝，
        // 不允许「留空即不校」——否则完整性保护可被发布时漏填绕过
        if (!preg_match('/^[0-9a-f]{64}$/', $expectSha)) {
            throw new ApiException('版本 ' . $version . ' 缺少有效的 sha256 校验值，主程序升级拒绝执行');
        }

        $ticket = StoreService::requestSystemDownloadTicket($package);
        $ticketSha = strtolower((string)($ticket['sha256'] ?? ''));
        // 选包结果与下载凭证双签校验：不一致（含票面为空）说明版本记录被改动，拒绝继续
        if ($ticketSha !== $expectSha) {
            throw new ApiException('版本 ' . $version . ' 的下载凭证与升级计划校验值不一致，已中止');
        }
        
        $zip = StoreService::downloadSystemPackage($ticket, $version);
        try {
            $actual = strtolower((string)Security::fileSha256($zip));
            if ($actual !== $expectSha) {
                throw new ApiException('版本 ' . $version . ' 安装包完整性校验失败（sha256 不匹配）');
            }
            // 端到端签名：以官方发布清单为准，取代原先随升级计划下发的逐包脱附签。
            // 计划/票面/落盘三处 sha256 同源于一次应答，只有清单哈希是市场私钥签过的
            ReleaseManifestService::check($zip, self::CORE_NAME, $version, 1, (string)($package['delta_from_version'] ?? ''));
            self::emitLine('版本 ' . $version . ' 包下载完成，sha256 校验通过（'
                . self::formatSize((int)filesize($zip)) . '）', $channel, $jobId);
            
            $staging = $workDir . '/' . $subDir;
            Directory::ensureDir($staging);
            FileService::extractZipSafely($zip, $staging);
            return self::resolvePackageRoot($staging);
        } finally {
            @unlink($zip);
        }
    }

    /**
     * 解析包内容根目录：优先含 upgrade.json 的目录；其次唯一顶层目录；最后解压根。
     * （主程序包没有插件 manifest.json，不能用 findPackageSourceDir）
     */
    protected static function resolvePackageRoot(string $extractDir): string
    {
        $dir = Directory::findDirWithFile($extractDir, 'upgrade.json');
        if ($dir !== null) {
            return $dir;
        }
        $children = glob($extractDir . '/*') ?: [];
        $dirs = array_values(array_filter($children, 'is_dir'));
        $files = array_values(array_filter($children, 'is_file'));
        if (count($dirs) === 1 && !$files) {
            return (string)$dirs[0];
        }
        return $extractDir;
    }

    /**
     * 归一化单包升级配置。
     * changed_files 为空时以包内实际文件清单兜底（包只含差异文件，不会扩大覆盖面）。
     * unsupported 记下配置里已声明但本版未实现的键，由调用方显式提示，绝不静默丢弃。
     *
     * @return array{changed_files:array, migrations:array, need_restart:bool, config_version:string, unsupported:array}
     */
    protected static function packageConfig(array $package, string $root): array
    {
        $config = self::normalizeArray($package['upgrade_config'] ?? []);
        $changed = self::normalizePathList($config['changed_files'] ?? []);
        $migrations = self::migrationNames($config['migrations'] ?? []);

        $inPackage = self::collectFiles($root);
        if (!$changed) {
            $changed = $inPackage;
        } else {
            // 只保留包内确实存在的声明项，缺失即报错（避免"以为覆盖了其实没覆盖"）
            $missing = array_values(array_diff(self::keys($changed), self::keys($inPackage)));
            if ($missing) {
                throw new ApiException('版本 ' . (string)$package['version'] . ' 声明的差异文件在包内缺失：'
                    . implode(', ', array_slice($missing, 0, 5)));
            }
        }

        return [
            'changed_files' => array_values(array_filter($changed, static fn(string $p): bool => self::pathAllowed($p) !== '')),
            'migrations' => array_values(array_filter($migrations, static fn(string $p): bool => self::pathAllowed($p) !== '')),
            'need_restart' => (bool)($config['need_restart'] ?? false),
            'config_version' => (string)($config['version'] ?? ($package['version'] ?? '')),
            'unsupported' => self::unsupportedConfigKeys($config),
        ];
    }

    /** 挑出升级配置里已填值但本版尚未实现的键。 */
    protected static function unsupportedConfigKeys(array $config): array
    {
        $out = [];
        foreach (self::UNSUPPORTED_CONFIG as $key) {
            $value = $config[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && (!is_object($value) || (array)$value !== [])) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** 将未支持的配置项逐条输出到任务日志，告知操作者本次忽略而非失败。 */
    protected static function warnUnsupportedConfig(array $keys, string $version, string $channel, string $jobId): void
    {
        foreach ($keys as $key) {
            self::emitLine('【提示】版本 ' . $version . ' 的升级配置含 ' . $key
                . '，当前主程序版本尚未支持该字段，已忽略（其余步骤不受影响）', $channel, $jobId);
        }
    }

    // ───────────────────────────── 文件覆盖 ─────────────────────────────

    /**
     * 把 $sourceRoot 内声明的相对文件覆盖到目标根（默认基座根，merged 叠加模式为临时目录）。
     * 仅处理清单内文件，绝不整目录递归覆盖，也不触碰 .env / runtime / 上传目录。
     * 目标为基座根时，覆盖前先把旧文件镜像到 $preDir（失败时的精确回滚依据）。
     */
    protected static function applyFiles(string $sourceRoot, array $relativeFiles, string $jobId, string $channel, ?string $targetRoot = null, string $preDir = ''): int
    {
        $toBase = $targetRoot === null;
        $targetRoot ??= base_path();

        // 先完整校验本轮清单，再建快照、再覆盖：避免覆盖了一半才发现某个文件缺失
        $safeList = [];
        foreach ($relativeFiles as $relative) {
            $safe = self::pathAllowed((string)$relative);
            if ($safe === '') {
                self::emitLine('跳过不允许覆盖的路径：' . $relative, $channel, $jobId);
                continue;
            }
            if (!is_file($sourceRoot . '/' . $safe)) {
                throw new ApiException('升级包内缺少文件：' . $safe);
            }
            $safeList[] = $safe;
        }
        if (!$safeList) {
            throw new ApiException('升级包没有可覆盖的文件');
        }

        if ($toBase && $preDir !== '') {
            Directory::ensureDir($preDir);
            self::snapshotPre($preDir, $safeList);
        }

        $count = 0;
        foreach ($safeList as $safe) {
            $target = $targetRoot . '/' . $safe;
            Directory::ensureDir(dirname($target));
            if (!@copy($sourceRoot . '/' . $safe, $target)) {
                throw new ApiException('覆盖文件失败：' . $safe . '（请检查目录写权限）');
            }
            $count++;
        }
        return $count;
    }

    /**
     * 前端构建产物孤儿清理：本次升级包向 public/assets/ 投放了文件，即视为后台前端整目录重建
     * （与 am-web post-build 的 rm -rf assets + 重新拷贝对齐），删除现存但不属于本次产物集的孤儿 chunk。
     * 删除前逐文件登记 pre 快照，失败回滚可还原；仅处理 public/assets，不触碰其它目录。
     *
     * @param array<int,string> $incomingFiles 本次将覆盖到基座的相对路径清单
     * @return int 实际删除的孤儿文件数
     */
    protected static function pruneOrphanAssets(string $preDir, array $incomingFiles, string $channel, string $jobId): int
    {
        $prefix = 'public/assets/';
        $keep = [];
        foreach ($incomingFiles as $f) {
            $f = (string)$f;
            if (str_starts_with($f, $prefix)) {
                $keep[$f] = true;
            }
        }
        if (!$keep) {
            return 0; // 本次不含前端产物，不动 assets
        }
        $assetsAbs = base_path() . '/public/assets';
        if (!is_dir($assetsAbs)) {
            return 0;
        }
        $orphans = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsAbs, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel = $prefix . str_replace('\\', '/', substr($file->getPathname(), strlen($assetsAbs) + 1));
            if (!isset($keep[$rel])) {
                $orphans[] = $rel;
            }
        }
        if (!$orphans) {
            return 0;
        }
        if ($preDir !== '') {
            self::snapshotPre($preDir, $orphans);
        }
        $removed = 0;
        foreach ($orphans as $rel) {
            $abs = base_path() . '/' . $rel;
            if (!is_file($abs) || @unlink($abs)) {
                $removed++;
            }
        }
        self::emitLine('清理后台前端构建孤儿文件 ' . $removed . ' 个（public/assets 随本次前端重建刷新）', $channel, $jobId);
        return $removed;
    }

    /**
     * 自定义后台入口同步：升级包覆盖 public/index.html 后，若本站配置了非默认入口
     * （.env 的 APP_ENTRY_FILE，如 manage_XXXX.html），把新入口内容落到该文件名并删除默认 index.html，
     * 与 am-web post-build 的改名逻辑一致——否则改名过的站点其真实入口拿不到更新、默认入口又会“复活”。
     * 仅当本次确实覆盖了 public/index.html 且入口名非默认时才动作；覆盖前对真实入口登记 pre 快照以便回滚。
     *
     * @param array<int,string> $appliedFiles 本次已覆盖到基座的相对路径清单
     */
    protected static function syncAdminEntry(string $preDir, array $appliedFiles, string $channel, string $jobId): void
    {
        $applied = [];
        foreach ($appliedFiles as $f) {
            $applied[(string)$f] = true;
        }
        if (!isset($applied['public/index.html'])) {
            return; // 本次没有新的后台入口产物，保持原样
        }
        $entryFile = trim((string)env('APP_ENTRY_FILE', 'index.html'));
        if ($entryFile === '' || $entryFile === 'index.html' || str_contains($entryFile, '/') || str_contains($entryFile, '..')) {
            return; // 默认入口或异常值：不处理
        }
        $indexAbs = base_path() . '/public/index.html';
        $entryAbs = base_path() . '/public/' . $entryFile;
        if (!is_file($indexAbs)) {
            return;
        }
        // 真实入口覆盖前先镜像旧内容（回滚依据）：已存在→existed，不存在→new
        if ($preDir !== '') {
            self::snapshotPre($preDir, ['public/' . $entryFile]);
        }
        if (@copy($indexAbs, $entryAbs)) {
            @unlink($indexAbs); // 与 post-build 一致：删除默认入口，避免 /index.html 重新可访问
            self::emitLine('已按 APP_ENTRY_FILE 同步后台入口为 ' . $entryFile . '，并移除默认 index.html', $channel, $jobId);
        } else {
            // 必须抛异常触发回滚：孤儿 assets 已在覆盖前被清理，入口若没更新成功会引用到已删的旧 chunk 而白屏
            throw new ApiException('后台入口同步为 ' . $entryFile . ' 失败（请检查 public 目录写权限），已中止并回滚');
        }
    }

    /** 路径安全检查：通过返回归一化相对路径，否则返回空串。 */
    protected static function pathAllowed(string $relative): string
    {
        $path = str_replace('\\', '/', trim($relative));
        $path = ltrim($path, '/');
        if ($path === '') {
            return '';
        }
        if (preg_match('#^[A-Za-z]:#', $path) || str_contains($path, '://')) {
            return '';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return '';
            }
        }
        $top = explode('/', $path)[0];
        if (in_array($top, self::DENY_TOP, true)) {
            return '';
        }
        foreach (self::DENY_PREFIX as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return '';
            }
        }
        return $path;
    }

    /** 包元数据文件：不参与覆盖（upgrade.json 供解析；signature.json 属历史包残留，一并跳过） */
    private const PACKAGE_META = ['upgrade.json', 'signature.json'];

    /** 收集目录下全部文件的相对路径（相对 $root，统一 / 分隔，排除包元数据）。 */
    protected static function collectFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || in_array($file->getFilename(), self::PACKAGE_META, true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($prefix)));
            $out[] = $relative;
        }
        sort($out);
        return $out;
    }

    // ───────────────────────────── 迁移与依赖 ─────────────────────────────

    /**
     * 执行主程序迁移（相对基座根的 sql 文件），按 TYPE_SYSTEM 记录，已成功的跳过（幂等）。
     * 本批真正执行过的脚本名记到工作目录，供失败回滚时撤销对应迁移记录。
     */
    protected static function runMigrations(array $migrations, string $from, string $to, string $jobId, string $channel, string $workDir = ''): void
    {
        if (!$migrations) {
            return;
        }
        $batch = (int)Migration::where('app_name', self::CORE_NAME)
            ->where('migration_type', Migration::TYPE_SYSTEM)
            ->max('batch') + 1;

        foreach ($migrations as $relative) {
            $safe = self::pathAllowed((string)$relative);
            if ($safe === '') {
                throw new ApiException('迁移文件路径不合法：' . $relative);
            }
            $name = basename($safe);
            if (Migration::where('app_name', self::CORE_NAME)
                ->where('migration_type', Migration::TYPE_SYSTEM)
                ->where('migration', $name)
                ->where('status', Migration::STATUS_SUCCESS)
                ->count() > 0
            ) {
                self::emitLine('迁移已执行过，跳过：' . $name, $channel, $jobId);
                continue;
            }

            $path = base_path() . '/' . $safe;
            if (!is_file($path)) {
                throw new ApiException('迁移文件不存在：' . $safe . '（升级包可能不完整）');
            }
            try {
                DatabaseService::executeSqlFile($path);
                self::saveMigration($name, $safe, $from, $to, $batch, Migration::STATUS_SUCCESS);
                self::recordAppliedMigration($workDir, $name);
                self::emitLine('迁移执行成功：' . $name, $channel, $jobId);
            } catch (\Throwable $e) {
                self::saveMigration($name, $safe, $from, $to, $batch, Migration::STATUS_FAILED, $e->getMessage());
                throw new ApiException('迁移执行失败（' . $name . '）：' . $e->getMessage());
            }
        }
    }

    /** 写迁移记录（TYPE_SYSTEM）。 */
    protected static function saveMigration(string $name, string $relative, string $from, string $to, int $batch, int $status, string $error = ''): void
    {
        try {
            $record = Migration::where('app_name', self::CORE_NAME)
                ->where('migration_type', Migration::TYPE_SYSTEM)
                ->where('migration', $name)
                ->find();
            if (!$record) {
                $record = new Migration();
            }
            $record->save([
                'app_name' => self::CORE_NAME,
                'migration_type' => Migration::TYPE_SYSTEM,
                'version' => $status === Migration::STATUS_SUCCESS ? $to : $from,
                'migration' => $name,
                'checksum' => '',
                'from_version' => $from,
                'to_version' => $to,
                'batch' => $batch,
                'status' => $status,
                'error_message' => $error,
                'executed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 写迁移记录失败 ' . $name . '：' . $e->getMessage());
        }
    }

    /** 在升级任务内同步安装声明的 composer 依赖（作用域=基座根 composer.json）。 */
    protected static function runComposer(array $requires, string $jobId, string $channel): void
    {
        if (!$requires) {
            return;
        }
        $declared = [];
        foreach ($requires as $pkg => $constraint) {
            $declared[] = $constraint !== '' ? "$pkg:$constraint" : (string)$pkg;
        }
        self::emitLine('本步需安装依赖 ' . count($requires) . ' 个：' . implode(', ', $declared), $channel, $jobId);

        $exit = PluginComposerService::streamRequire(
            $requires,
            static function (string $line) use ($jobId, $channel): void {
                CoreUpgradeService::emitLine($line, $channel, $jobId);
            }
        );
        if ($exit !== 0) {
            throw new ApiException('composer 依赖安装失败（退出码 ' . $exit . '），请查看上方输出');
        }
        self::emitLine('依赖安装完成。', $channel, $jobId);
    }

    // ───────────────────────────── 版本落库 ─────────────────────────────

    /**
     * 版本号唯一写入点：只写 manifest.json 的 version/release，并清掉 .env 里的 APP_VERSION/APP_RELEASE 残键。
     * 写入失败必须抛出（回滚），否则后续检查会以错误基准选包。
     * 这俩文件不在增量包差异清单里（.env 更是覆盖黑名单），故一并并入 pre 快照，回滚时连版本号与环境文件一起还原。
     */
    protected static function persistVersion(string $version, string $jobId, string $channel, string $preDir = ''): void
    {
        $manifestPath = base_path() . '/manifest.json';
        $envPath = base_path() . '/.env';
        if (!is_file($manifestPath)) {
            throw new ApiException('manifest.json 不存在，版本号无法落库，已中止（请确认安装完整）');
        }

        if ($preDir !== '') {
            Directory::ensureDir($preDir);
            self::snapshotPre($preDir, array_filter(
                ['manifest.json', '.env'],
                static fn(string $name): bool => is_file(base_path() . '/' . $name)
            ));
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new ApiException('manifest.json 解析失败，无法写入版本号');
        }
        $manifest['version'] = $version;
        $manifest['release'] = date('Ymd');
        if (@file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new ApiException('manifest.json 写入失败（请检查目录写权限）');
        }

        if (is_file($envPath)) {
            $content = (string)file_get_contents($envPath);
            $cleaned = preg_replace('/^\s*APP_(?:VERSION|RELEASE)\s*=.*\R/m', '', $content);
            if (is_string($cleaned) && $cleaned !== $content) {
                if (@file_put_contents($envPath, $cleaned) === false) {
                    throw new ApiException('.env 清理废弃版本键失败（请检查文件写权限）');
                }
                self::emitLine('已清理 .env 中废弃的 APP_VERSION / APP_RELEASE 键。', $channel, $jobId);
            }
        }

        self::emitLine('版本号已写入 manifest.json：' . $version, $channel, $jobId);
    }

    // ───────────────────────────── 文件级回滚快照 ─────────────────────────────

    /**
     * 覆盖基座文件前建立逐文件镜像（增量级备份）。
     * 基座整站备份清单不含 app/，只有这里能精确回滚本次真正改过的文件。
     * 索引值：existed=已镜像旧件；new=本次新增（回滚时删除）；lost=镜像失败（回滚不保证成功）。
     */
    protected static function snapshotPre(string $preDir, array $relativeFiles): void
    {
        if ($preDir === '' || !$relativeFiles) {
            return;
        }
        $index = self::readPreIndex($preDir);
        foreach ($relativeFiles as $relative) {
            // 同一文件在链上被多个包覆盖：只保留最早的原始镜像
            if (isset($index[$relative])) {
                continue;
            }
            $target = base_path() . '/' . $relative;
            if (!is_file($target)) {
                $index[$relative] = 'new';
                continue;
            }
            $mirror = $preDir . '/' . $relative;
            Directory::ensureDir(dirname($mirror));
            $index[$relative] = @copy($target, $mirror) ? 'existed' : 'lost';
            if ($index[$relative] === 'lost') {
                Log::warning('[CoreUpgrade] 建立回滚快照失败：' . $relative . '（该文件无法自动回滚）');
            }
        }
        self::writePreIndex($preDir, $index);
    }

    /**
     * 按 pre 快照还原基座：existed 复制回原位，new 删除本次新增件。
     * SQL 脚本与 composer 变更不可逆，只撤销迁移记录、依赖只提示不卸载。
     *
     * @return array{restored:int,removed:int,failed:array}
     */
    protected static function restorePre(string $preDir, string $channel, string $jobId): array
    {
        $index = self::readPreIndex($preDir);
        $restored = 0;
        $removed = 0;
        $failed = [];
        if (!$index) {
            self::emitLine('本次未登记任何基座文件快照，无需文件级回滚。', $channel, $jobId);
            return ['restored' => 0, 'removed' => 0, 'failed' => []];
        }

        foreach ($index as $relative => $state) {
            $relative = (string)$relative;
            $target = base_path() . '/' . $relative;
            if ($state === 'existed') {
                $mirror = $preDir . '/' . $relative;
                Directory::ensureDir(dirname($target));
                if (is_file($mirror) && @copy($mirror, $target)) {
                    $restored++;
                } else {
                    $failed[] = $relative;
                }
            } elseif ($state === 'new') {
                if (!is_file($target) || @unlink($target)) {
                    $removed++;
                } else {
                    $failed[] = $relative;
                }
            } else {
                // lost：覆盖前就连快照都没存下来，无法回滚
                $failed[] = $relative;
            }
        }

        self::emitLine('文件级回滚完成：还原 ' . $restored . ' 个、删除本次新增 ' . $removed
            . ' 个' . ($failed ? '，失败 ' . count($failed) . ' 个（' . implode(', ', array_slice($failed, 0, 5)) . '）' : '。'), $channel, $jobId);
        return ['restored' => $restored, 'removed' => $removed, 'failed' => $failed];
    }

    /** 读 pre 快照索引（缺失/损坏返回空数组）。 */
    protected static function readPreIndex(string $preDir): array
    {
        $file = $preDir . '/' . self::PRE_INDEX;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** 写 pre 快照索引（失败只记日志，不阻断升级）。 */
    protected static function writePreIndex(string $preDir, array $index): void
    {
        try {
            Directory::ensureDir($preDir);
            if (@file_put_contents($preDir . '/' . self::PRE_INDEX, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
                Log::warning('[CoreUpgrade] 写回滚快照索引失败，自动回滚能力受限');
            }
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 写回滚快照索引异常：' . $e->getMessage());
        }
    }

    /** 登记本批已成功执行的迁移脚本（供回滚时撤销记录）。 */
    protected static function recordAppliedMigration(string $workDir, string $name): void
    {
        if ($workDir === '') {
            return;
        }
        try {
            $file = $workDir . '/' . self::APPLIED_FILE;
            $list = is_file($file) ? (array)(json_decode((string)file_get_contents($file), true) ?: []) : [];
            if (!in_array($name, $list, true)) {
                $list[] = $name;
            }
            Directory::ensureDir($workDir);
            @file_put_contents($file, json_encode($list, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 登记已执行迁移失败 ' . $name . '：' . $e->getMessage());
        }
    }

    /**
     * 撤销本次新增的迁移记录（SQL 已执行的内容不可逆，只能保持记录与实际一致）。
     * 同时提醒操作者：表结构可能已部分变更，需人工确认。
     */
    protected static function revertAppliedMigrations(string $workDir, string $jobId, string $channel): void
    {
        $file = $workDir . '/' . self::APPLIED_FILE;
        if (!is_file($file)) {
            return;
        }
        $list = array_values(array_filter((array)(json_decode((string)@file_get_contents($file), true) ?: [])));
        if (!$list) {
            return;
        }
        try {
            Migration::where('app_name', self::CORE_NAME)
                ->where('migration_type', Migration::TYPE_SYSTEM)
                ->where('status', Migration::STATUS_SUCCESS)
                ->whereIn('migration', $list)
                ->delete();
            self::emitLine('已撤销本次迁移记录 ' . count($list) . ' 条；数据库脚本不可逆，表结构可能已部分变更，请人工确认。', $channel, $jobId);
        } catch (\Throwable $e) {
            Log::error('[CoreUpgrade] 撤销迁移记录失败 job=' . $jobId . '：' . $e->getMessage());
            self::emitLine('【提示】迁移记录撤销失败（' . $e->getMessage() . '），请人工核对 system_migration 表。', $channel, $jobId);
        }
    }

    // ───────────────────────────── 维护模式 ─────────────────────────────

    /** 置全局维护标记。 */
    protected static function enterMaintenance(string $jobId, string $from, string $to): void
    {
        Redis::setex(self::MAINT_KEY, self::MAINT_TTL, json_encode([
            'job_id' => $jobId,
            'from' => $from,
            'to' => $to,
            'since' => time(),
            'since_text' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** 解除维护标记（Redis 不可用时忽略，标记有兜底 TTL）。 */
    protected static function exitMaintenance(): void
    {
        try {
            Redis::del(self::MAINT_KEY);
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 解除维护模式失败：' . $e->getMessage());
        }
    }

    /**
     * 把维护标记钉住：回滚不彻底时的最后防线。
     *
     * 必须重写入 hold 并拉长 TTL：否则 finally 释放锁后，isMaintaining() 的残留自愈
     * 会立即把维护标记拆掉，站点就带着「部分已覆盖、部分未还原」的半升级代码对外服务。
     * 之后人工有两种收尾：修好环境重新升级（成功即 exitMaintenance），或用备份人工恢复。
     */
    protected static function holdMaintenance(string $jobId, string $reason): void
    {
        try {
            $info = self::maintenanceInfo();
            Redis::setex(self::MAINT_KEY, self::HOLD_TTL, json_encode([
                'job_id' => $jobId,
                'from' => (string)($info['from'] ?? ''),
                'to' => (string)($info['to'] ?? ''),
                'since' => (int)($info['since'] ?? time()),
                'since_text' => (string)($info['since_text'] ?? date('Y-m-d H:i:s')),
                'hold' => 1,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 钉不住就只剩锁 TTL 兜底，属重大风险，必须显著记错
            Log::error('[CoreUpgrade] 钉住维护标记失败 job=' . $jobId . '，站点可能在半升级状态下对外服务：' . $e->getMessage());
        }
    }

    /**
     * 保留回滚快照：把 pre 镜像从待清理的工作目录搬出，供人工做文件级恢复。
     * 回滚不彻底时工作目录里的镜像会被 finally 删掉，人工就没了原始文件依据。
     * @return string 保留后的目录（失败返回空串）
     */
    protected static function preservePre(string $preDir, string $jobId): string
    {
        if (!is_dir($preDir)) {
            return '';
        }
        $kept = runtime_path() . '/core_upgrade/' . $jobId . '.pre';
        try {
            if (is_dir($kept)) {
                Directory::removeDirWithin($kept, runtime_path() . '/core_upgrade');
            }
            if (@rename($preDir, $kept)) {
                return $kept;
            }
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 保留回滚快照失败 job=' . $jobId . '：' . $e->getMessage());
        }
        return '';
    }

    // ───────────────────────────── 内部工具 ─────────────────────────────

    /** 按版本升序排序增量链（服务端已有序，本地兜底，防叠加顺序错乱）。 */
    protected static function sortPackages(array $packages): array
    {
        $packages = array_values(array_filter($packages, static fn($p): bool => is_array($p) && !empty($p['version'])));
        usort($packages, static fn(array $a, array $b): int => version_compare((string)$a['version'], (string)$b['version']));
        return $packages;
    }

    /** 校验增量链连续性（首包基准须为当前版本，后包基准须为前包版本）。 */
    protected static function assertChainContinuity(array $packages, string $current): void
    {
        $cursor = $current;
        foreach ($packages as $package) {
            $from = (string)($package['delta_from_version'] ?? '');
            $version = (string)($package['version'] ?? '');
            // 用 version_compare 而非字符串相等判链：1.1 与 1.1.0 视为同一基准（与服务端同口径）
            if ($from !== '' && $cursor !== '' && version_compare($from, $cursor, '!=')) {
                throw new ApiException(sprintf(
                    '升级链不连续：期望基准 %s，实际包基准 %s（目标版本 %s），已中止',
                    $cursor,
                    $from,
                    $version
                ));
            }
            if ($version !== '' && $cursor !== '' && version_compare($version, $cursor, '<=')) {
                throw new ApiException('升级包版本 ' . $version . ' 不高于当前基准 ' . $cursor . '，已中止');
            }
            $cursor = $version;
        }
    }

    /**
     * 汇总全部包的元数据（用于计划展示与 merged 模式基线）。
     * @return array{0:array,1:array,2:array,3:bool,4:int,5:array,6:bool}
     */
    protected static function mergeMeta(array $packages): array
    {
        $composer = [];
        $migrations = [];
        $changed = [];
        $needRestart = false;
        $size = 0;
        $unsupported = [];
        $changedDeclared = true;
        foreach ($packages as $package) {
            $composer = self::mergeRequires($composer, self::normalizeRequires($package['composer_requires'] ?? []));
            $config = self::normalizeArray($package['upgrade_config'] ?? []);
            // 与执行侧 packageConfig() 同一口径解析，否则计划与执行会自相矛盾（同页两个数字不一样）
            foreach (self::migrationNames($config['migrations'] ?? []) as $item) {
                if (!in_array($item, $migrations, true)) {
                    $migrations[] = $item;
                }
            }
            $declared = self::normalizePathList($config['changed_files'] ?? []);
            if (!$declared) {
                $changedDeclared = false;
            }
            foreach ($declared as $item) {
                if (!in_array($item, $changed, true)) {
                    $changed[] = $item;
                }
            }
            $needRestart = $needRestart || (bool)($config['need_restart'] ?? false);
            $size += (int)($package['size'] ?? 0);
            foreach (self::unsupportedConfigKeys($config) as $key) {
                if (!in_array($key, $unsupported, true)) {
                    $unsupported[] = $key;
                }
            }
        }
        return [$composer, $migrations, $changed, $needRestart, $size, $unsupported, $changedDeclared];
    }

    /**
     * 合并依赖声明：同一包取更高约束（按约束内首个版本号比较）。
     * merged 模式取最高约束不报错；sequential 模式靠 filterStrongerRequires
     * 采用同一口径，保证两种模式最终 composer.json 一致。
     */
    protected static function mergeRequires(array $base, array $extra): array
    {
        foreach ($extra as $pkg => $constraint) {
            $pkg = trim((string)$pkg);
            if ($pkg === '') {
                continue;
            }
            $constraint = trim((string)$constraint);
            $base[$pkg] = isset($base[$pkg])
                ? self::stronger((string)$base[$pkg], $constraint)
                : $constraint;
        }
        return $base;
    }

    /**
     * sequential 模式的依赖过滤：只安装「当前为止链上最高约束」的声明，
     * 已被更高/相同约束覆盖的条目归入 skipped（调用方逐条日志说明）。
     * $applied 以引用方式累计已生效约束。
     *
     * @return array{install:array, skipped:array}
     */
    protected static function filterStrongerRequires(array $requires, array &$applied): array
    {
        $merged = self::mergeRequires($applied, $requires);
        $install = [];
        $skipped = [];
        foreach ($requires as $pkg => $constraint) {
            $constraint = trim((string)$constraint);
            // 本声明不是胜者（链上已有更高约束），或已按相同约束装过 → 不重复装
            if ($merged[$pkg] !== $constraint || (isset($applied[$pkg]) && $applied[$pkg] === $constraint)) {
                $skipped[$pkg] = $constraint;
                continue;
            }
            $install[$pkg] = $constraint;
        }
        $applied = $merged;
        return ['install' => $install, 'skipped' => $skipped];
    }

    /** 取两个约束中更高的那个（无可比较版本号时以后声明者为准）。 */
    protected static function stronger(string $old, string $new): string
    {
        $oldVer = self::versionOf($old);
        $newVer = self::versionOf($new);
        if ($oldVer === '' || $newVer === '') {
            return $new;
        }
        return version_compare($oldVer, $newVer, '>') ? $old : $new;
    }

    /** 从版本约束提取首个版本号（^1.2.3 → 1.2.3），无法提取返回空串。 */
    protected static function versionOf(string $constraint): string
    {
        return preg_match('/\d+(?:\.\d+)*/', $constraint, $m) ? $m[0] : '';
    }

    /** 归一化依赖声明（对象/数组 → pkg => constraint）。 */
    protected static function normalizeRequires(mixed $requires): array
    {
        $requires = self::normalizeArray($requires);
        $out = [];
        foreach ($requires as $pkg => $constraint) {
            $pkg = trim((string)$pkg);
            if ($pkg === '') {
                continue;
            }
            $out[$pkg] = is_array($constraint) ? '' : trim((string)$constraint);
        }
        return $out;
    }

    /** 归一化路径清单（兼容对象形态条目）。 */
    protected static function normalizePathList(mixed $list): array
    {
        $out = [];
        foreach ((array)$list as $item) {
            if (is_array($item)) {
                $item = (string)($item['path'] ?? $item['file'] ?? '');
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * 归一化升级配置里的迁移清单：兼容字符串项与 {path}/{migration} 对象项。
     * 计划侧（mergeMeta）与执行侧（packageConfig）共用本方法，两边解析口径必须一致。
     */
    protected static function migrationNames(mixed $migrations): array
    {
        $out = [];
        foreach ((array)$migrations as $item) {
            if (is_array($item)) {
                $item = (string)($item['path'] ?? $item['migration'] ?? '');
            }
            $safe = self::pathAllowed(trim((string)$item));
            if ($safe !== '') {
                $out[] = $safe;
            }
        }
        return $out;
    }

    /** 兼容模型 json 字段 / stdClass / 数组三种形态的取值归一化。 */
    protected static function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return json_decode((string)json_encode($value), true) ?: [];
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** 转成可 json_encode / 可数组访问的载荷（stdClass → array）。 */
    protected static function jsonSafe(mixed $data): array
    {
        return json_decode((string)json_encode($data), true) ?: [];
    }

    /** 以 '/' 分隔的键集合（用于跨来源路径比对）。 */
    protected static function keys(array $paths): array
    {
        return array_map(static fn(string $p): string => trim(str_replace('\\', '/', $p), '/'), $paths);
    }

    protected static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    protected static function channel(string $jobId): string
    {
        return 'core-upgrade-' . $jobId;
    }

    protected static function writeSnapshot(string $jobId, array $data): void
    {
        // 进度载体属“尽力而为”：写失败只记日志，绝不因进度存储失败而抛异常（会被队列重投重跑升级）
        try {
            Redis::setex(self::SNAP_PREFIX . $jobId, self::TTL, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 写任务快照失败 job=' . $jobId . '：' . $e->getMessage());
        }
    }

    protected static function updateSnapshot(string $jobId, array $patch): void
    {
        try {
            $raw = Redis::get(self::SNAP_PREFIX . $jobId);
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $data = array_merge($data, $patch);
            $data['updated_at'] = date('Y-m-d H:i:s');
            Redis::setex(self::SNAP_PREFIX . $jobId, self::TTL, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 更新任务快照失败 job=' . $jobId . '：' . $e->getMessage());
        }
    }

    /** 单行日志：写任务日志列表 + 推 push（供升级进度页展示）。本方法吞异常并顺带作锁心跳。 */
    public static function emitLine(string $line, string $channel, string $jobId): void
    {
        try {
            $key = self::LOG_PREFIX . $jobId;
            Redis::rPush($key, $line);
            Redis::lTrim($key, -self::LOG_CAP, -1);
            Redis::expire($key, self::TTL);
            // 累计行号不受 lTrim 影响：轮询降级靠它判断“是否有新行”，否则日志超 500 行后进度会永久冻结
            $totalKey = $key . ':total';
            Redis::incr($totalKey);
            Redis::expire($totalKey, self::TTL);
            PushService::toChannel($channel, 'log', ['line' => $line]);
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 写任务日志失败 job=' . $jobId . '：' . $e->getMessage());
        }
        // 任务仍在跑就续锁 TTL（长任务如 composer 可能超过锁时长），按 60s 节流
        self::touchLock();
    }

    protected static function push(string $channel, string $event, array $data): void
    {
        try {
            PushService::toChannel($channel, $event, $data);
        } catch (\Throwable $e) {
            Log::warning('[CoreUpgrade] 推送事件失败 ' . $event . '：' . $e->getMessage());
        }
    }

    /** 仅当锁仍属于本任务令牌时续期，避免误续他人锁。 */
    protected static function touchLock(): void
    {
        if (self::$lockToken === '' || time() - self::$lastTouch < 60) {
            return;
        }
        self::$lastTouch = time();
        try {
            if ((string)Redis::get(self::LOCK_KEY) === self::$lockToken) {
                Redis::expire(self::LOCK_KEY, self::LOCK_TTL);
            }
        } catch (\Throwable $e) {
            // 忽略：续期失败最多导致锁提前释放，维护标记另有自愈
        }
    }

    /**
     * 释放串行锁：按令牌比对后再删。
     * 令牌为空（旧载荷 / 非任务来源）时绝不裸删，宁可等 TTL 过期，也不删别人的锁。
     */
    protected static function releaseLock(?string $token = null): void
    {
        $token = (string)($token ?? self::$lockToken);
        if ($token === '') {
            return;
        }
        try {
            if ((string)Redis::get(self::LOCK_KEY) === $token) {
                Redis::del(self::LOCK_KEY);
            }
        } catch (\Throwable $e) {
            // 忽略，锁随 TTL 自动释放
        }
    }
}
