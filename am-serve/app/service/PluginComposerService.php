<?php

namespace app\service;

use app\exception\ApiException;
use app\model\plugin\App as PluginApp;
use Composer\InstalledVersions;
use support\Log;
use support\Redis;

/**
 * 插件 Composer 依赖一键安装服务（全局合并到基座根 vendor）。
 *
 * 依赖数据源：插件 manifest 的 composer.require（包名 => 版本约束）。
 * 检测（check）纯离线：用 Composer\InstalledVersions 判存在/版本，composer/semver 判约束满足，
 * 结果写入 plugin_app.composer_state；安装（dispatchInstall → runInstall）经 plugin_job 队列在
 * 消费进程异步执行 composer require，输出逐行经 webman/push 推给前端（网页终端式），
 * 成功后再检测并触发平滑重载（Linux SIGUSR1）。
 *
 * 安全铁律：composer_enabled 默认关闭；全局串行锁避免并发写坏 lock；监听/执行失败仅记日志不影响主流程。
 */
class PluginComposerService
{
    /** 任务快照 key 前缀（string，json） */
    private const SNAP_PREFIX = 'atrman:plugin:composer:job:';

    /** 任务日志行 key 前缀（list） */
    private const LOG_PREFIX = 'atrman:plugin:composer:log:';

    /** 全局串行锁 key（改的是共享根 composer.json/lock，同一时刻仅允许一个安装） */
    private const LOCK_KEY = 'atrman:plugin:composer:lock';

    /** 任务/日志快照 TTL（秒） */
    private const TTL = 3600;

    /** 锁 TTL（秒），需覆盖 composer 最长耗时 */
    private const LOCK_TTL = 1200;

    /** 日志行保留上限（末 N 行，供订阅时历史回填） */
    private const LOG_CAP = 200;

    /** 队列 handler 名（基座在 PluginBootstrapper 注册，消费进程内可解析） */
    public const HANDLER = 'base_plugin_composer_install';

    // ───────────────────────────── 数据源与检测 ─────────────────────────────

    /** 读插件 manifest 的 composer.require（pkg => constraint）。 */
    public static function resolveRequirements(string $name): array
    {
        $plugin = PluginApp::where('name', $name)->findOrEmpty();
        if ($plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        $manifest = PluginManifestService::getManifest($plugin);
        return PluginManifestService::normalizeComposer($manifest['composer'] ?? [])['require'];
    }

    /**
     * 检测依赖满足情况（已装/缺失/不兼容）并写 composer_state（弹窗首步，纯离线不跑 composer）。
     * @return array{requires,installed,missing,incompatible,status,php_ok,php_version,semver_available,env}
     */
    public static function check(string $name): array
    {
        $plugin = PluginApp::where('name', $name)->findOrEmpty();
        if ($plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        $manifest = PluginManifestService::getManifest($plugin);
        $requires = PluginManifestService::normalizeComposer($manifest['composer'] ?? [])['require'];

        $semverClass = 'Composer\\Semver\\Semver';
        $semverAvailable = class_exists($semverClass);
        // 仅用于 call_user_func 动态调用，避免静态类型解析在依赖未装时报错
        $installed = [];
        $missing = [];
        $incompatible = [];

        foreach ($requires as $pkg => $constraint) {
            if (self::isPackageInstalled($pkg)) {
                $version = self::installedVersion($pkg);
                $satisfied = true;
                if ($constraint !== '' && $semverAvailable) {
                    try {
                        $satisfied = (bool)call_user_func([$semverClass, 'satisfies'], $version, $constraint);
                    } catch (\Throwable $e) {
                        // 约束表达式非法等：无法判定，保守视为已装（composer require 会再校验）
                        $satisfied = true;
                    }
                }
                if ($satisfied) {
                    $installed[$pkg] = $version;
                } else {
                    $installed[$pkg] = $version;
                    $incompatible[$pkg] = ['version' => $version, 'want' => $constraint];
                }
            } else {
                $missing[$pkg] = $constraint;
            }
        }

        $status = self::computeStatus($requires, $installed, $missing, $incompatible);

        $state = [
            'requires' => $requires,
            'installed' => $installed,
            'missing' => $missing,
            'incompatible' => $incompatible,
            'status' => $status,
            'php_ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        // 保留历史 last_job，仅覆盖检测字段
        $existing = \normalizeArray($plugin->composer_state) ?: [];
        if (isset($existing['last_job'])) {
            $state['last_job'] = $existing['last_job'];
        }
        $plugin->composer_state = $state;
        $plugin->save();

        return $state + [
            'semver_available' => $semverAvailable,
            'php_version' => PHP_VERSION,
            'env' => self::probeEnv($requires),
        ];
    }

    /** 探测运行环境（composer 是否安装/版本、proc_open、可写、开关、白名单）。 */
    public static function probeEnv(array $requires = []): array
    {
        $procOpenOk = function_exists('proc_open') && !self::isFunctionDisabled('proc_open');
        // worker 进程 PATH 可能与终端不同（服务/计划任务启动、用户级 PATH 未继承），
        // 配置命令探测失败时自动回退 where/which 查找与常见安装路径
        [$composerInstalled, $composerVersion, $resolvedBin, $probeError] = self::locateComposer($procOpenOk);

        $allowlist = (array)config('plugin_extend.composer_allowlist', []);
        $notAllowed = [];
        foreach (array_keys($requires) as $pkg) {
            if (!self::packageAllowed($pkg, $allowlist)) {
                $notAllowed[] = $pkg;
            }
        }

        return [
            'composer_enabled' => (bool)config('plugin_extend.composer_enabled', false),
            'composer_bin' => $resolvedBin,
            'composer_installed' => $composerInstalled,
            'composer_version' => $composerVersion,
            'composer_probe_error' => $probeError,
            'proc_open_available' => $procOpenOk,
            'vendor_writable' => is_writable(base_path() . '/vendor') && is_writable(base_path() . '/composer.json'),
            'php_version' => PHP_VERSION,
            'allowlist' => $allowlist,
            'not_allowed' => $notAllowed,
            'is_windows' => DIRECTORY_SEPARATOR === '\\',
        ];
    }

    /**
     * 定位可用的 composer：先试配置命令，再按 where/which → 常见安装路径逐个探测。
     * @return array{0:bool,1:string,2:string,3:string} [是否可用, 版本, 生效命令, 失败摘要]
     */
    protected static function locateComposer(bool $procOpenOk): array
    {
        $configured = trim((string)config('plugin_extend.composer_bin', 'composer')) ?: 'composer';
        $errors = [];
        if (!$procOpenOk) {
            return [false, '', $configured, 'php 已禁用 proc_open'];
        }

        $tryBin = function (string $bin) use (&$errors): ?string {
            [$code, $out] = self::runShell(escapeshellarg($bin) . ' --version 2>&1');
            if ($code === 0 && stripos($out, 'composer') !== false) {
                return $out;
            }
            $errors[] = $bin . ' → ' . ($out !== '' ? mb_substr($out, 0, 120) : 'exit ' . $code);
            return null;
        };

        // 1) 配置命令直接探测
        $out = $tryBin($configured);
        if ($out !== null) {
            return [true, self::parseVersion($out), $configured, ''];
        }

        // 2) PATH 查找（where/which）：即使 worker 环境 PATH 不含目录，cmd 内建 where 比直接调用的解析更宽；
        //    找到绝对路径后逐个探测
        $found = [];
        $lookup = DIRECTORY_SEPARATOR === '\\'
            ? ['where', 'where.exe composer.bat composer composer.phar']
            : ['which', 'command -v composer'];
        [$code, $lookupOut] = self::runShell(escapeshellarg($lookup[0]) . ' ' . $lookup[1] . ' 2>&1');
        if ($code === 0 || $lookupOut !== '') {
            foreach (preg_split('/\r?\n/', $lookupOut) as $line) {
                $line = trim($line);
                if ($line !== '' && (str_contains($line, DIRECTORY_SEPARATOR) || str_contains($line, '/'))) {
                    $found[] = $line;
                }
            }
        }

        // 3) 常见安装路径兜底（Windows 安装器/手动解压 + Linux 全局 + 基座目录 composer.phar）
        $candidates = $found;
        $candidates[] = base_path() . DIRECTORY_SEPARATOR . 'composer.phar';
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\ProgramData\\ComposerSetup\\bin\\composer.bat';
            $candidates[] = ($home = getenv('USERPROFILE') ?: '') ? $home . '\\Composer\\vendor\\bin\\composer.bat' : '';
            $candidates[] = ($local = getenv('LOCALAPPDATA') ?: '') ? $local . '\\Programs\\Composer\\Composer.exe' : '';
            $candidates[] = 'C:\\Composer\\composer.phar';
        } else {
            $home = getenv('HOME') ?: '';
            $candidates[] = '/usr/local/bin/composer';
            $candidates[] = '/usr/bin/composer';
            $candidates[] = $home !== '' ? $home . '/.local/bin/composer' : '';
            $candidates[] = $home !== '' ? $home . '/composer.phar' : '';
        }
        // 候选均为具体文件路径，仅探测确实存在者，避免误探无关命令
        foreach (array_unique(array_filter($candidates)) as $bin) {
            if ($bin === $configured || !is_file($bin)) {
                continue;
            }
            $out = $tryBin($bin);
            if ($out !== null) {
                return [true, self::parseVersion($out), $bin, ''];
            }
        }

        return [false, '', $configured, implode(' | ', array_slice($errors, 0, 3))];
    }

    /** 从 composer --version 输出提取版本号。 */
    protected static function parseVersion(string $out): string
    {
        return preg_match('/Composer version\s+([^\s]+)/i', $out, $m) ? $m[1] : '';
    }

    // ───────────────────────────── 安装派发（HTTP 侧） ─────────────────────────────

    /**
     * 校验环境并投递安装任务到 plugin_job 队列。
     * @param array $targets pkg => constraint 子集（仅装缺失/不兼容）
     * @return array{job_id, channel}
     */
    public static function dispatchInstall(string $name, array $targets, int $operatorId = 0): array
    {
        if (!config('plugin_extend.composer_enabled', false)) {
            throw new ApiException('插件 Composer 安装未开启（plugin_extend.composer_enabled=false）');
        }
        $requires = self::resolveRequirements($name);
        if (!$requires) {
            throw new ApiException('该插件未声明 composer 依赖');
        }
        // targets 必须是声明依赖的子集，防注入任意包
        $targets = self::filterTargets($targets, $requires);
        if (!$targets) {
            throw new ApiException('没有需要安装的依赖（均已满足）');
        }
        // 再过包白名单：filterTargets 只保证「插件自己声明过」，声明什么就装什么等于让插件方
        // 指定任意包进基座 vendor。与 streamRequire（主程序路径）保持同一口径。
        $allowlist = (array)config('plugin_extend.composer_allowlist', []);
        if (!$allowlist) {
            throw new ApiException('plugin_extend.composer_allowlist 为空，在线安装一律拒绝；请先填入允许的包前缀（如 ["tinywan/","topthink/"]）');
        }
        $allowed = array_filter(
            $targets,
            fn(string $pkg): bool => self::packageAllowed($pkg, $allowlist),
            ARRAY_FILTER_USE_KEY
        );
        if (!$allowed) {
            throw new ApiException('待安装依赖均不在 plugin_extend.composer_allowlist 白名单内，拒绝安装');
        }
        $targets = $allowed;

        $env = self::probeEnv($targets);
        if (!$env['proc_open_available']) {
            throw new ApiException('服务器 PHP 已禁用 proc_open，无法在线安装，请手动执行：' . self::manualCommand($targets));
        }
        if (!$env['composer_installed']) {
            throw new ApiException('未检测到 composer，无法在线安装，请安装 composer 后手动执行：' . self::manualCommand($targets));
        }
        if (!$env['vendor_writable']) {
            throw new ApiException('基座 vendor / composer.json 不可写，请检查服务器权限');
        }

        // 全局串行锁：改的是共享根 vendor，同一时刻只允许一个安装任务
        // support\Redis 走 illuminate/redis，set 需位置参数（'EX', ttl, 'NX'），不能传 phpredis 风格选项数组
        $locked = Redis::set(self::LOCK_KEY, (string)$operatorId, 'EX', self::LOCK_TTL, 'NX');
        if (!$locked) {
            throw new ApiException('已有依赖安装任务正在进行，请稍后再试');
        }

        $jobId = bin2hex(random_bytes(8));
        self::writeSnapshot($jobId, [
            'name' => $name,
            'status' => 'queued',
            'exit_code' => null,
            'reloaded' => null,
            'message' => '任务已排队，等待队列消费进程执行',
            'operator_id' => $operatorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $dispatched = QueueService::dispatch(self::HANDLER, [
            'name' => $name,
            'job_id' => $jobId,
            'targets' => $targets,
            'operator_id' => $operatorId,
        ]);
        if (!$dispatched) {
            self::releaseLock();
            Redis::del(self::SNAP_PREFIX . $jobId);
            throw new ApiException('队列不可用，无法异步安装，请手动执行：' . self::manualCommand($targets));
        }

        return ['job_id' => $jobId, 'channel' => self::channel($jobId)];
    }

    /** 读任务快照 + 历史日志行（供订阅回填 / push 不可用时降级轮询）。 */
    public static function status(string $jobId): ?array
    {
        $snapRaw = Redis::get(self::SNAP_PREFIX . $jobId);
        if (!$snapRaw) {
            return null;
        }
        $snap = json_decode($snapRaw, true) ?: [];
        $snap['log'] = Redis::lRange(self::LOG_PREFIX . $jobId, 0, -1) ?: [];
        return $snap;
    }

    // ───────────────────────────── 队列执行体（消费进程侧） ─────────────────────────────

    /** 队列 handler 主体：异步跑 composer require，逐行推流，完成后重载。 */
    public static function runInstall(array $payload): void
    {
        $jobId = (string)($payload['job_id'] ?? '');
        $name = (string)($payload['name'] ?? '');
        $targets = (array)($payload['targets'] ?? []);
        if ($jobId === '' || !$targets) {
            return;
        }
        $channel = self::channel($jobId);

        try {
            self::updateSnapshot($jobId, ['status' => 'running', 'message' => '开始安装依赖']);
            self::push($channel, 'status', ['status' => 'running']);

            $bin = trim((string)config('plugin_extend.composer_bin', 'composer')) ?: 'composer';
            // 与探测保持一致：若 worker PATH 取不到配置命令，回退到实际定位到的绝对路径
            [$composerOk, , $resolvedBin] = self::locateComposer(true);
            $bin = $composerOk ? $resolvedBin : $bin;
            $args = '';
            foreach ($targets as $pkg => $constraint) {
                $spec = $constraint !== '' ? "$pkg:$constraint" : $pkg;
                $args .= ' ' . escapeshellarg($spec);
            }
            $cmd = escapeshellarg($bin) . ' require' . $args
                . ' -n --no-progress --no-interaction --with-all-dependencies 2>&1';

            $exitCode = self::runProcess($cmd, $channel, $jobId, (int)config('plugin_extend.composer_timeout', 600));

            if ($exitCode === 0) {
                // 复核依赖并刷新 last_job
                $reload = self::gracefulReload();
                self::persistLastJob($name, $jobId, 'success');
                self::updateSnapshot($jobId, [
                    'status' => 'success',
                    'exit_code' => 0,
                    'reloaded' => $reload['ok'],
                    'message' => $reload['ok'] ? '依赖安装完成，已平滑重载生效' : '依赖安装完成，需手动重启服务生效',
                ]);
                self::push($channel, 'done', [
                    'status' => 'success',
                    'exit_code' => 0,
                    'reloaded' => $reload,
                ]);
            } else {
                self::persistLastJob($name, $jobId, 'failed');
                self::updateSnapshot($jobId, [
                    'status' => 'failed',
                    'exit_code' => $exitCode,
                    'reloaded' => null,
                    'message' => 'composer 执行失败，退出码 ' . $exitCode,
                ]);
                self::push($channel, 'done', [
                    'status' => 'failed',
                    'exit_code' => $exitCode,
                    'reloaded' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[PluginComposer] 安装任务执行异常：' . $e->getMessage());
            self::updateSnapshot($jobId, ['status' => 'failed', 'message' => $e->getMessage()]);
            self::push($channel, 'done', ['status' => 'failed', 'exit_code' => -1, 'message' => $e->getMessage()]);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * 非阻塞流式执行子进程，逐行推送到 push 频道并留存 Redis 日志。
     * @return int 退出码（超时 kill 返回 124）
     */
    protected static function runProcess(string $cmd, string $channel, string $jobId, int $timeout): int
    {
        return self::runProcessInternal($cmd, function (string $line) use ($channel, $jobId) {
            self::emitLine($line, $channel, $jobId);
        }, $timeout);
    }

    /**
     * 主程序升级作用域：在任务内同步安装根 composer.json 声明的依赖。
     *
     * 与插件安装同一作用域（基座根 vendor），但不经队列、不写插件任务快照：
     * 输出逐行交给调用方传入的 $onLine（由 CoreUpgradeService 转写升级任务日志 + push）。
     * 沿用全局串行锁，避免与插件依赖安装并发写坏 composer.lock。
     *
     * @param array $requires 已合并的 pkg => constraint（均来自主程序发布记录的声明依赖）
     * @param callable $onLine fn(string $line): void
     * @return int 退出码（0 成功）
     */
    public static function streamRequire(array $requires, callable $onLine, int $timeout = 0): int
    {
        if (!$requires) {
            return 0;
        }
        $timeout = $timeout > 0 ? $timeout : (int)config('plugin_extend.composer_timeout', 600);

        $env = self::probeEnv($requires);
        if (!$env['composer_enabled']) {
            throw new ApiException('Composer 在线安装未开启（plugin_extend.composer_enabled=false），请手动执行：' . self::manualCommand($requires));
        }
        if (!$env['proc_open_available']) {
            throw new ApiException('服务器 PHP 已禁用 proc_open，无法在线安装依赖，请手动执行：' . self::manualCommand($requires));
        }
        if (!$env['composer_installed']) {
            throw new ApiException('未检测到 composer，无法在线安装依赖，请安装 composer 后手动执行：' . self::manualCommand($requires));
        }
        if (!$env['vendor_writable']) {
            throw new ApiException('基座 vendor / composer.json 不可写，请检查服务器权限');
        }

        // 白名单过滤：仅安装允许前缀内的包，防注入
        $allowlist = (array)config('plugin_extend.composer_allowlist', []);
        if (!$allowlist) {
            throw new ApiException('plugin_extend.composer_allowlist 为空，无法在线安装主程序依赖，请先填白名单或手动执行：' . self::manualCommand($requires));
        }
        $targets = [];
        foreach ($requires as $pkg => $constraint) {
            $pkg = trim((string)$pkg);
            if ($pkg === '' || !self::packageAllowed($pkg, $allowlist)) {
                continue;
            }
            $targets[$pkg] = is_string($constraint) ? trim($constraint) : (string)$constraint;
        }
        if (!$targets) {
            $onLine('没有符合白名单的依赖需要安装，已跳过 composer 步骤。');
            return 0;
        }

        // 与插件安装共用全局串行锁（改的是共享根 composer.json/lock）
        $locked = Redis::set(self::LOCK_KEY, 'core_upgrade', 'EX', self::LOCK_TTL, 'NX');
        if (!$locked) {
            throw new ApiException('已有依赖安装任务正在进行，无法执行主程序依赖安装，请稍后重试');
        }

        try {
            $bin = trim((string)config('plugin_extend.composer_bin', 'composer')) ?: 'composer';
            // 与探测保持一致：worker PATH 取不到配置命令时回退到实际定位到的绝对路径
            [$composerOk, , $resolvedBin] = self::locateComposer(true);
            $bin = $composerOk ? $resolvedBin : $bin;

            $args = '';
            foreach ($targets as $pkg => $constraint) {
                $spec = $constraint !== '' ? "$pkg:$constraint" : $pkg;
                $args .= ' ' . escapeshellarg($spec);
            }
            $cmd = escapeshellarg($bin) . ' require' . $args
                . ' -n --no-progress --no-interaction --with-all-dependencies 2>&1';

            return self::runProcessInternal($cmd, $onLine, $timeout);
        } finally {
            self::releaseLock();
        }
    }

    /**
     * 流式执行子进程的内部实现：每解析完一行回调 $onLine。
     * @return int 退出码（超时 kill 返回 124）
     */
    protected static function runProcessInternal(string $cmd, callable $onLine, int $timeout): int
    {
        $nullDev = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDev, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, base_path(), self::subprocessEnv());
        if (!is_resource($proc)) {
            throw new ApiException('无法启动 composer 进程');
        }

        $stdout = $pipes[1];
        $stderr = $pipes[2];
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $deadline = time() + max(30, $timeout);
        $buffers = ['out' => '', 'err' => ''];
        $exitCode = null;

        while (true) {
            $status = proc_get_status($proc);
            $read = [$stdout, $stderr];
            $write = $except = null;
            // 1s 轮询，避免忙等
            @stream_select($read, $write, $except, 1);
            foreach ($read as $stream) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $key = $stream === $stdout ? 'out' : 'err';
                self::consumeBufferInternal($buffers[$key] . $chunk, $buffers[$key], $onLine);
            }

            if (time() > $deadline) {
                proc_terminate($proc, 9);
                $onLine('【超时】composer 执行超过 ' . $timeout . 's，已终止。');
                $exitCode = 124;
                break;
            }

            if (!$status['running']) {
                // proc_get_status 退出码键为 exitcode（非 code）
                $exitCode = (int)($status['exitcode'] ?? -1);
                // 读完残余输出
                self::drainRemainingInternal($stdout, 'out', $buffers, $onLine);
                self::drainRemainingInternal($stderr, 'err', $buffers, $onLine);
                break;
            }
        }

        @fclose($stdout);
        @fclose($stderr);
        $closeCode = proc_close($proc);
        // 若在轮询已捕获退出码则用之，否则用 proc_close 结果
        return $exitCode ?? (int)$closeCode;
    }

    /** 把缓冲区里成行的输出逐行交给回调，余下不完整行留在 buffer。 */
    protected static function consumeBufferInternal(string $data, string &$buffer, callable $onLine): void
    {
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $pos), "\r\n");
            $buffer = substr($buffer, $pos + 1);
            $onLine($line);
        }
    }

    /** 进程结束后读取残余（无换行收尾）。 */
    protected static function drainRemainingInternal($stream, string $key, array &$buffers, callable $onLine): void
    {
        while (!feof($stream)) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            self::consumeBufferInternal($chunk, $buffers[$key], $onLine);
        }
        if (trim($buffers[$key]) !== '') {
            $onLine(trim($buffers[$key]));
            $buffers[$key] = '';
        }
    }

    /** 单行输出：推 push + 追加 Redis 日志列表。 */
    protected static function emitLine(string $line, string $channel, string $jobId): void
    {
        self::appendLog($jobId, $line);
        self::push($channel, 'log', ['line' => $line]);
    }

    // ───────────────────────────── 平滑重载 ─────────────────────────────

    /**
     * 安装后程序化生效：Linux 向主进程发 SIGUSR1 平滑重启；其余返回手动提示。
     */
    public static function gracefulReload(): array
    {
        $isLinux = DIRECTORY_SEPARATOR === '/' && function_exists('posix_kill') && function_exists('posix_getppid');
        if ($isLinux) {
            $pid = (function_exists('posix_getppid')) ? posix_getppid() : 0;
            $sig = defined('SIGUSR1') ? SIGUSR1 : 10;
            if ($pid > 0 && @posix_kill($pid, $sig)) {
                return ['ok' => true, 'method' => 'sigusr1', 'message' => '已向主进程（pid ' . $pid . '）发送 SIGUSR1，worker 正在平滑重启。'];
            }
        }
        return [
            'ok' => false,
            'method' => 'manual',
            'message' => DIRECTORY_SEPARATOR === '\\'
                ? 'Windows 环境请重启 php windows.php 使新依赖生效。'
                : '请手动执行 php start.php reload 使新依赖生效。',
        ];
    }

    // ───────────────────────────── 内部工具 ─────────────────────────────

    protected static function channel(string $jobId): string
    {
        return 'composer-' . $jobId;
    }

    protected static function isPackageInstalled(string $pkg): bool
    {
        try {
            return InstalledVersions::isInstalled($pkg);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function installedVersion(string $pkg): string
    {
        try {
            $v = InstalledVersions::getPrettyVersion($pkg);
            return $v ?: InstalledVersions::getReference($pkg) ?: '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function computeStatus(array $requires, array $installed, array $missing, array $incompatible): string
    {
        if (!$requires) {
            return 'none';
        }
        if ($missing && count($missing) === count($requires)) {
            return 'missing';
        }
        if (!$missing && !$incompatible) {
            return 'satisfied';
        }
        return 'partial';
    }

    /** 仅保留属于插件声明范围的包（防注入任意 require）。 */
    protected static function filterTargets(array $targets, array $requires): array
    {
        $allowed = [];
        foreach ($targets as $pkg => $constraint) {
            $pkg = trim((string)$pkg);
            if (isset($requires[$pkg])) {
                $allowed[$pkg] = is_string($constraint) && $constraint !== '' ? $constraint : $requires[$pkg];
            }
        }
        return $allowed;
    }

    protected static function packageAllowed(string $pkg, array $allowlist): bool
    {
        // 空名单一律拒绝（fail-closed）：开启 composer_enabled 必须先填 composer_allowlist。
        // 旧的「空=不限」等于把基座整站依赖交给插件方自报，声明什么就装什么。
        if (!$allowlist) {
            return false;
        }
        foreach ($allowlist as $prefix) {
            if ($prefix !== '' && str_starts_with($pkg, (string)$prefix)) {
                return true;
            }
        }
        return false;
    }

    protected static function manualCommand(array $targets): string
    {
        $args = '';
        foreach ($targets as $pkg => $constraint) {
            $args .= ' "' . ($constraint !== '' ? "$pkg:$constraint" : $pkg) . '"';
        }
        return 'composer require' . $args . ' -n --with-all-dependencies';
    }

    protected static function persistLastJob(string $name, string $jobId, string $result): void
    {
        try {
            $plugin = PluginApp::where('name', $name)->findOrEmpty();
            if ($plugin->isEmpty()) {
                return;
            }
            $state = \normalizeArray($plugin->composer_state) ?: [];
            $state['last_job'] = ['id' => $jobId, 'result' => $result, 'at' => date('Y-m-d H:i:s')];
            $plugin->composer_state = $state;
            $plugin->save();
        } catch (\Throwable $e) {
            Log::warning('[PluginComposer] 写 last_job 失败：' . $e->getMessage());
        }
    }

    protected static function writeSnapshot(string $jobId, array $data): void
    {
        Redis::setex(self::SNAP_PREFIX . $jobId, self::TTL, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected static function updateSnapshot(string $jobId, array $patch): void
    {
        $raw = Redis::get(self::SNAP_PREFIX . $jobId);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
        $data = array_merge($data, $patch);
        $data['updated_at'] = date('Y-m-d H:i:s');
        Redis::setex(self::SNAP_PREFIX . $jobId, self::TTL, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected static function appendLog(string $jobId, string $line): void
    {
        $key = self::LOG_PREFIX . $jobId;
        Redis::rPush($key, $line);
        Redis::lTrim($key, -self::LOG_CAP, -1);
        Redis::expire($key, self::TTL);
    }

    protected static function push(string $channel, string $event, array $data): void
    {
        PushService::toChannel($channel, $event, $data);
    }

    protected static function releaseLock(): void
    {
        try {
            Redis::del(self::LOCK_KEY);
        } catch (\Throwable $e) {
            // 忽略，锁会随 TTL 自动释放
        }
    }

    /** 跑一条 shell 命令，返回 [退出码, 输出文本]。 */
    protected static function runShell(string $cmd): array
    {
        $descriptors = [
            0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, self::subprocessEnv());
        if (!is_resource($proc)) {
            return [1, ''];
        }
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [(int)proc_close($proc), trim($out . $err)];
    }

    /** 检查 PHP 函数是否被 disable_functions 禁用。 */
    protected static function isFunctionDisabled(string $fn): bool
    {
        $disabled = (string)ini_get('disable_functions');
        if ($disabled === '') {
            return false;
        }
        $list = array_map('trim', explode(',', strtolower($disabled)));
        return in_array(strtolower($fn), $list, true);
    }

    /**
     * 构造子进程环境变量：在继承当前进程环境基础上显式注入 HOME/COMPOSER_HOME。
     * webman 常以服务/守护进程启动，不携带登录 shell 的 HOME，composer 会直接报错退出，
     * 导致“未检测到 composer”误判。默认缓存家目录放在系统临时目录，也可由配置指定。
     */
    protected static function subprocessEnv(): array
    {
        $home = self::resolveComposerHome();
        $env = [];
        foreach (getenv() as $key => $value) {
            // Windows 会带 =C:= 这类盘符伪变量，回传给子进程会出问题，过滤
            if (str_contains((string)$key, '=')) {
                continue;
            }
            $env[(string)$key] = (string)$value;
        }
        $env['COMPOSER_HOME'] = $home;
        // HOME 主要供 Linux/macOS；Windows 下 composer 也接受，无害
        $env['HOME'] = $home;
        // 保底 PATH，便于 where/which 与依赖查找
        if (empty($env['PATH'])) {
            $env['PATH'] = DIRECTORY_SEPARATOR === '\\'
                ? 'C:\\Windows\\system32;C:\\Windows'
                : '/usr/local/bin:/usr/bin:/bin';
        }
        return $env;
    }

    /** 解析 composer 家目录（缓存/配置存放）：优先配置，否则系统临时目录下专用目录。 */
    protected static function resolveComposerHome(): string
    {
        $configured = trim((string)config('plugin_extend.composer_home', ''));
        $home = $configured !== '' ? $configured : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'am_composer_home';
        if (!is_dir($home)) {
            @mkdir($home, 0755, true);
        }
        return $home;
    }
}
