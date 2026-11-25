<?php

namespace app\service\system;

use app\database\SqlMigrationWrapper;
use app\exception\ApiException;
use app\model\system\Migration;
use app\utils\Directory;
use support\Log;
use support\Redis;

/**
 * Phinx 唯一调用入口：台账幂等 ensure 与旧库基线自动标记 → 动态生成配置 → proc_open 子进程执行 → 失败补记。
 * 记账由子进程内 MigrationAdapter 直接写 system_migration；连接参数经环境变量注入子进程，密码不落盘。
 * Phinx 未安装时自动降级 legacy 执行器（executeSqlFile + 直写台账）。
 */
class PhinxService
{
    public const SCOPE_BASE = '20';
    public const SCOPE_PLUGIN = '21';

    /** 基座在台账中的归属标识（与 CoreUpgradeService::CORE_NAME 一致） */
    public const BASE_APP = 'atrman';

    private const LOCK_KEY = 'atrman:db:migration:lock';
    private const LOCK_TTL = 900;
    private const EXEC_TIMEOUT = 1800;

    // ───────────────────────────── 对外 API ─────────────────────────────

    /**
     * 执行迁移。
     *
     * @param string $app 'base'（基座）或插件标识
     * @param array $paths 迁移目录（Phinx 只扫 PHP 迁移；legacy SQL 须先经 SqlMigrationWrapper 包装）
     * @param array $context 台账上下文：migration_type / from_version / to_version / ledger_map / files
     * @param array $db 显式连接参数（安装场景）；缺省读 env
     * @param string $target 目标版本（Phinx -t 语义是版本号，非步数）
     * @param bool $fake true=只标记不执行（Phinx 原生 --fake，台账写 status=0）
     * @param callable|null $onLine 子进程输出逐行回调（升级进度页）
     * @return array{exit:int, log:string, applied:array}
     */
    public static function migrate(string $app, array $paths, array $context = [], array $db = [], string $target = '', bool $fake = false, ?callable $onLine = null): array
    {
        self::assertApp($app);
        $db = self::dbParams($db);
        $lock = self::acquireLock();
        $config = '';
        try {
            self::ensureSchema($db);
            self::ensureBaseline($app, $db);
            self::checksumValidate($app, $paths, $context, $db);

            if (!self::available()) {
                return self::legacyFallback($app, $context, $db, $onLine);
            }

            $context['batch'] = self::nextBatch($app, $context, $db);
            $map = (array)($context['ledger_map'] ?? self::dslMap($paths));
            $config = self::writeConfig($app, $paths, $context, $map, $db, $fake);

            $before = self::appliedNames($app, $db);
            $args = [$config, 'migrate', '-e', 'production'];
            if ($target !== '') {
                $args[] = '-t';
                $args[] = $target;
            }
            if ($fake) {
                $args[] = '--fake';
            }
            if (!empty($context['dry_run'])) {
                $args[] = '--dry-run';
            }
            $execTimeout = max(30, (int)($context['exec_timeout'] ?? self::EXEC_TIMEOUT));
            $result = self::exec($args, $db, $onLine, self::heartbeat($lock), $execTimeout);
            $after = self::appliedNames($app, $db);
            $result['applied'] = array_values(array_diff($after, $before));
            if ($result['exit'] !== 0) {
                self::recordFailures($app, $before, $after, $map, $context, $result, $db);
            }
            return $result;
        } finally {
            self::releaseLock($lock);
            if ($config !== '') {
                @unlink($config);
            }
        }
    }

    /** 回滚到目标版本（'0' = 全部）。legacy 包装迁移无 down()，Phinx 会拒绝回滚——如实口径。 */
    public static function rollback(string $app, string $target = '0', array $db = [], ?callable $onLine = null): array
    {
        self::assertApp($app);
        $db = self::dbParams($db);
        if (!self::available()) {
            throw new ApiException('Phinx 未安装，无法回滚');
        }
        $lock = self::acquireLock();
        $config = '';
        try {
            self::ensureSchema($db);
            $config = self::writeConfig($app, self::appPaths($app), [], [], $db, false);
            return self::exec([$config, 'rollback', '-e', 'production', '-t', $target], $db, $onLine, self::heartbeat($lock));
        } finally {
            self::releaseLock($lock);
            if ($config !== '') {
                @unlink($config);
            }
        }
    }

    /** 迁移状态（Phinx status 输出）。 */
    public static function status(string $app, array $db = []): array
    {
        self::assertApp($app);
        $db = self::dbParams($db);
        if (!self::available()) {
            throw new ApiException('Phinx 未安装，无法查看状态');
        }
        $config = '';
        try {
            self::ensureSchema($db);
            self::ensureBaseline($app, $db);
            $config = self::writeConfig($app, self::appPaths($app), [], [], $db, false);
            return self::exec([$config, 'status', '-e', 'production'], $db, null, null);
        } finally {
            if ($config !== '') {
                @unlink($config);
            }
        }
    }

    /** 旧库基线标记（幂等）：补列 + 回填 phinx_version + 自动 MARKED。migrate 内已自动执行，本方法供 CLI 显式调用。 */
    public static function mark(string $app, array $db = []): array
    {
        self::assertApp($app);
        $db = self::dbParams($db);
        self::ensureSchema($db);
        $marked = self::ensureBaseline($app, $db);
        return ['app' => $app, 'marked' => $marked];
    }

    /** 执行 Seeder（全新安装的初始数据）。 */
    public static function seedRun(array $db = [], string $seeder = 'BaseSeeder', ?callable $onLine = null): array
    {
        $db = self::dbParams($db);
        if (!self::available()) {
            throw new ApiException('Phinx 未安装，无法执行初始数据播种');
        }
        $lock = self::acquireLock();
        $config = '';
        try {
            self::ensureSchema($db);
            $config = self::writeConfig('base', self::appPaths('base'), [], [], $db, false);
            return self::exec([$config, 'seed:run', '-e', 'production', '-s', $seeder], $db, $onLine, self::heartbeat($lock));
        } finally {
            self::releaseLock($lock);
            if ($config !== '') {
                @unlink($config);
            }
        }
    }

    /**
     * 全新安装基座：执行 database/migrations 全部 PHP 迁移（InitialSchema + 后续 DSL 迁移，
     * Phinx 按时间戳排序、只扫 .php，legacy .sql 不会被执行）+ 播种。
     * install.sql 冻结为初始基线，后续表结构变更一律走 DSL 迁移，安装时自动补跑。
     */
    public static function installBase(array $db, ?callable $onLine = null): array
    {
        $db = self::dbParams($db);
        $paths = self::appPaths('base');
        if (!is_file(base_path() . '/database/migrations/20010000000001_InitialSchema.php')) {
            throw new ApiException('初始迁移文件缺失：database/migrations/20010000000001_InitialSchema.php');
        }
        $version = self::manifestVersion();
        $map = self::dslMap($paths);
        // InitialSchema 的有效载荷是 install.sql，校验值记 install.sql 的哈希
        $map['InitialSchema'] = [
            'original_name' => 'InitialSchema',
            'version' => $version,
            'checksum' => hash_file('sha256', base_path() . '/install/install.sql') ?: '',
            'phinx_version' => SqlMigrationWrapper::timestamp(self::SCOPE_BASE, '1.0.0', 1),
        ];
        $result = self::migrate('base', $paths, [
            'migration_type' => Migration::TYPE_SYSTEM,
            'from_version' => '',
            'to_version' => $version,
            'ledger_map' => $map,
        ], $db, '', false, $onLine);
        if ($result['exit'] !== 0) {
            throw new ApiException('初始 schema 迁移失败：' . self::logTail($result['log']));
        }
        $seed = self::seedRun($db, 'BaseSeeder', $onLine);
        if ($seed['exit'] !== 0) {
            throw new ApiException('初始数据播种失败：' . self::logTail($seed['log']));
        }
        // 存量升级 SQL 已含于 install.sql → 标记为已执行（MARKED）
        self::ensureBaseline('base', $db);
        return $result;
    }

    /**
     * 执行前完整性校验：台账已有 SUCCESS/MARKED 记录且源文件仍在的迁移，比对 sha256；
     * 不一致抛异常（文件被篡改，拒绝继续）。新文件无记录 → 跳过。
     */
    public static function checksumValidate(string $app, array $paths, array $context = [], array $db = []): void
    {
        $db = self::dbParams($db);
        $pdo = self::pdo($db);
        $table = self::ledgerTable($db);
        $appName = self::appName($app);

        $check = static function (string $migrationName, string $file) use ($pdo, $table, $appName): void {
            if (!is_file($file)) {
                return;
            }
            $stmt = $pdo->prepare("SELECT checksum FROM {$table} WHERE app_name = ? AND migration = ? AND status IN (0,1) AND delete_time IS NULL LIMIT 1");
            $stmt->execute([$appName, $migrationName]);
            $expected = (string)$stmt->fetchColumn();
            if ($expected === '') {
                return;
            }
            $actual = hash_file('sha256', $file) ?: '';
            if (!hash_equals($expected, $actual)) {
                throw new ApiException('迁移文件完整性校验失败（sha256 不匹配），拒绝执行：' . $migrationName);
            }
        };

        // 包装迁移：校验原始源文件（上下文 files 携带原始路径；复制件已替换占位符，哈希不同属正常）
        foreach ((array)($context['files'] ?? []) as $file) {
            if (!empty($file['original_name']) && !empty($file['sql_path'])) {
                $check((string)$file['original_name'], (string)$file['sql_path']);
            }
        }
        // DSL 迁移：校验 PHP 文件本身；InitialSchema 的有效载荷是 install.sql
        foreach ($paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                if (!preg_match('/^\d{14}_([A-Za-z0-9_]+)\.php$/', basename($file), $m)) {
                    continue;
                }
                if ($m[1] === 'InitialSchema') {
                    $check('InitialSchema', base_path() . '/install/install.sql');
                } else {
                    $check($m[1], $file);
                }
            }
        }
    }

    /** Phinx 是否已安装（vendor 存在且 runner 就绪）。 */
    public static function available(): bool
    {
        return is_file(base_path() . '/vendor/robmorgan/phinx/app/phinx.php')
            && is_file(base_path() . '/app/database/phinx-runner.php');
    }

    /** CLI/脚手架用：应用对应的迁移目录。 */
    public static function appPaths(string $app): array
    {
        if ($app === 'base') {
            return [base_path() . '/database/migrations'];
        }
        $dir = base_path() . '/plugin/' . $app . '/database/migrations';
        return is_dir($dir) ? [$dir] : [];
    }

    /** 确定性时间戳生成（代理 SqlMigrationWrapper，供脚手架/CLI 使用）。 */
    public static function nextTimestamp(string $scope, string $semanticVersion, int $seq = 1): string
    {
        return SqlMigrationWrapper::timestamp($scope, $semanticVersion, $seq);
    }

    /** 从文件名提取语义版本（取最后一个 x.y.z）。 */
    public static function parseSqlVersion(string $filename): string
    {
        return SqlMigrationWrapper::parseVersion($filename);
    }

    // ───────────────────────────── 台账 ensure（无缝升级核心） ─────────────────────────────

    /** 幂等确保台账结构（父进程侧，安装场景 .env 尚未写入，必须用显式连接）。 */
    private static function ensureSchema(array $db): void
    {
        $pdo = self::pdo($db);
        $table = self::ledgerTable($db);
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote(str_replace('_', '\\_', $table)))->fetchColumn();
        if (!$exists) {
            // 全新安装且 Phinx 不可用的降级路径：先建台账（结构与 MigrationAdapter 一致）
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$quoted} (
                  `id` int NOT NULL AUTO_INCREMENT,
                  `app_name` varchar(64) NOT NULL,
                  `migration_type` tinyint NOT NULL DEFAULT '1',
                  `version` varchar(50) NOT NULL DEFAULT '',
                  `migration` varchar(160) NOT NULL,
                  `phinx_version` bigint NOT NULL DEFAULT '0',
                  `checksum` varchar(64) NOT NULL DEFAULT '',
                  `from_version` varchar(50) NOT NULL DEFAULT '',
                  `to_version` varchar(50) NOT NULL DEFAULT '',
                  `batch` int NOT NULL DEFAULT '0',
                  `status` tinyint NOT NULL DEFAULT '1',
                  `error_message` varchar(500) NOT NULL DEFAULT '',
                  `executed_at` datetime DEFAULT NULL,
                  `created_by` int DEFAULT '0',
                  `updated_by` int DEFAULT '0',
                  `create_time` int DEFAULT '0',
                  `update_time` int DEFAULT '0',
                  `delete_time` int DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_system_migration` (`app_name`,`migration`),
                  KEY `idx_app_version` (`app_name`,`version`),
                  KEY `idx_migration_type` (`migration_type`),
                  KEY `idx_status` (`status`),
                  KEY `idx_app_phinx_version` (`app_name`,`phinx_version`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统迁移记录表'"
            );
            return;
        }
        $hasColumn = $pdo->query('SHOW COLUMNS FROM ' . $quoted . ' LIKE ' . $pdo->quote('phinx_version'))->fetchColumn();
        if (!$hasColumn) {
            $pdo->exec(
                "ALTER TABLE {$quoted}
                  ADD COLUMN `phinx_version` bigint NOT NULL DEFAULT '0' COMMENT 'Phinx确定性时间戳版本' AFTER `migration`,
                  ADD KEY `idx_app_phinx_version` (`app_name`,`phinx_version`)"
            );
        }
    }

    /** 旧库基线自动标记（幂等）：回填 phinx_version、为已部署基座/已安装插件补 MARKED 行。返回处理行数。 */
    private static function ensureBaseline(string $app, array $db): int
    {
        $pdo = self::pdo($db);
        $table = self::ledgerTable($db);
        $appName = self::appName($app);
        $touched = 0;

        // 1. 回填存量行的 phinx_version（按语义版本分组、组内按迁移名排序分配序号）
        $stmt = $pdo->prepare("SELECT id, migration_type, version, migration FROM {$table} WHERE app_name = ? AND phinx_version = 0 AND delete_time IS NULL ORDER BY version ASC, migration ASC");
        $stmt->execute([$appName]);
        $seqByVersion = [];
        $update = $pdo->prepare("UPDATE {$table} SET phinx_version = ? WHERE id = ?");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $version = (string)$row['version'];
            if ($version === '') {
                continue;
            }
            $seq = ($seqByVersion[$version] ?? 0) + 1;
            $seqByVersion[$version] = $seq;
            $scope = (int)$row['migration_type'] === Migration::TYPE_PLUGIN ? self::SCOPE_PLUGIN : self::SCOPE_BASE;
            try {
                $update->execute([SqlMigrationWrapper::timestamp($scope, $version, $seq), (int)$row['id']]);
                $touched++;
            } catch (\Throwable $e) {
                Log::warning('[Phinx] 回填 phinx_version 失败 id=' . $row['id'] . '：' . $e->getMessage());
            }
        }

        $hasRow = static function (string $migration) use ($pdo, $table, $appName): bool {
            $s = $pdo->prepare("SELECT id FROM {$table} WHERE app_name = ? AND migration = ? AND delete_time IS NULL LIMIT 1");
            $s->execute([$appName, $migration]);
            return (bool)$s->fetchColumn();
        };

        if ($app === 'base') {
            // 2. 已部署基座：InitialSchema 自动 MARKED（不重跑建库 SQL）
            $installed = (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote(str_replace('_', '\\_', $db['prefix'] . 'system_user')))->fetchColumn();
            $manifestVersion = self::manifestVersion();
            if ($installed && !$hasRow('InitialSchema')) {
                self::insertLedgerRow($pdo, $table, [
                    'app_name' => $appName,
                    'migration_type' => Migration::TYPE_SYSTEM,
                    'version' => $manifestVersion,
                    'migration' => 'InitialSchema',
                    'phinx_version' => SqlMigrationWrapper::timestamp(self::SCOPE_BASE, '1.0.0', 1),
                    'checksum' => is_file(base_path() . '/install/install.sql') ? (hash_file('sha256', base_path() . '/install/install.sql') ?: '') : '',
                    'status' => Migration::STATUS_MARKED,
                ]);
                $touched++;
            }
            // 3. 已含于安装 schema 的旧基座升级 SQL（版本 <= 当前版本）无记录 → MARKED
            if ($installed) {
                $legacy = [];
                foreach (glob(base_path() . '/database/migrations/*.sql') ?: [] as $file) {
                    $version = self::parseSqlVersion(basename($file));
                    if ($version !== '' && version_compare($version, $manifestVersion, '<=') && !$hasRow(basename($file))) {
                        $legacy[] = ['file' => $file, 'version' => $version];
                    }
                }
                usort($legacy, static fn($a, $b) => version_compare($a['version'], $b['version']) ?: strcmp(basename($a['file']), basename($b['file'])));
                $seqByVersion = [];
                foreach ($legacy as $item) {
                    $seq = ($seqByVersion[$item['version']] ?? 0) + 1;
                    $seqByVersion[$item['version']] = $seq;
                    self::insertLedgerRow($pdo, $table, [
                        'app_name' => $appName,
                        'migration_type' => Migration::TYPE_SYSTEM,
                        'version' => $item['version'],
                        'migration' => basename($item['file']),
                        'phinx_version' => SqlMigrationWrapper::timestamp(self::SCOPE_BASE, $item['version'], $seq),
                        'checksum' => hash_file('sha256', $item['file']) ?: '',
                        'status' => Migration::STATUS_MARKED,
                    ]);
                    $touched++;
                }
            }
        } else {
            // 4. 已安装插件的 install.sql（旧版安装不落台账）→ MARKED
            $pluginTable = '`' . str_replace('`', '``', $db['prefix'] . 'plugin_app') . '`';
            $pluginExists = (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote(str_replace('_', '\\_', $db['prefix'] . 'plugin_app')))->fetchColumn();
            if ($pluginExists) {
                $s = $pdo->prepare("SELECT version, status FROM {$pluginTable} WHERE name = ? AND delete_time IS NULL LIMIT 1");
                $s->execute([$app]);
                $plugin = $s->fetch(\PDO::FETCH_ASSOC);
                $installSql = base_path() . '/plugin/' . $app . '/install.sql';
                if ($plugin && in_array((int)$plugin['status'], [0, 1], true) && is_file($installSql) && !$hasRow('install.sql')) {
                    $version = (string)$plugin['version'];
                    self::insertLedgerRow($pdo, $table, [
                        'app_name' => $appName,
                        'migration_type' => Migration::TYPE_PLUGIN,
                        'version' => $version,
                        'migration' => 'install.sql',
                        'phinx_version' => SqlMigrationWrapper::timestamp(self::SCOPE_PLUGIN, $version !== '' ? $version : '1.0.0', 1),
                        'checksum' => hash_file('sha256', $installSql) ?: '',
                        'status' => Migration::STATUS_MARKED,
                    ]);
                    $touched++;
                }
            }
        }

        return $touched;
    }

    // ───────────────────────────── 配置生成与子进程 ─────────────────────────────

    /** 生成一次性配置（runtime/phinx/，不含密码；连接值经环境变量注入）。 */
    private static function writeConfig(string $app, array $paths, array $context, array $map, array $db, bool $markMode): string
    {
        $paths = array_values(array_filter($paths, 'is_dir'));
        if (!$paths) {
            // Phinx 要求至少一个有效迁移目录；固定空目录兜底（status/rollback 场景，避免临时目录累积）
            $empty = runtime_path() . '/phinx/empty';
            Directory::ensureDir($empty);
            $paths = [$empty];
        }
        $config = [
            'paths' => [
                'migrations' => $paths,
                'seeds' => base_path() . '/database/seeds',
            ],
            'environments' => [
                'production' => [
                    'adapter' => 'mysql',
                    'host' => '@@DB_HOST@@',
                    'port' => '@@DB_PORT@@',
                    'name' => '@@DB_NAME@@',
                    'user' => '@@DB_USER@@',
                    'pass' => '@@DB_PASS@@',
                    'charset' => '@@DB_CHARSET@@',
                    'table_prefix' => '@@DB_PREFIX@@',
                    'migration_table' => self::ledgerTable($db),
                    'app_name' => self::appName($app),
                    'mark_mode' => $markMode,
                    'ledger_context' => $context,
                    'ledger_map' => $map,
                ],
            ],
            'version_order' => 'creation',
        ];
        $code = "<?php\nreturn " . var_export($config, true) . ";\n";
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS', 'CHARSET', 'PREFIX'] as $key) {
            $code = str_replace("'@@DB_{$key}@@'", "getenv('PHINX_DB_{$key}')", $code);
        }
        $dir = runtime_path() . '/phinx';
        Directory::ensureDir($dir);
        $file = $dir . '/' . $app . '_' . bin2hex(random_bytes(6)) . '.php';
        if (file_put_contents($file, $code) === false) {
            throw new ApiException('迁移配置生成失败（runtime 不可写？）');
        }
        return $file;
    }

    /**
     * proc_open 执行 runner（数组命令，跨平台免转义），环境变量注入连接参数。
     * 输出逐行回调；超时终止；心跳续锁。
     */
    private static function exec(array $args, array $db, ?callable $onLine, ?callable $heartbeat, int $timeout = self::EXEC_TIMEOUT): array
    {
        $cmd = array_merge(
            [PHP_BINARY, base_path() . '/app/database/phinx-runner.php', base_path() . '/vendor/autoload.php'],
            $args
        );
        $env = array_merge(getenv(), $_ENV, [
            'PHINX_DB_HOST' => (string)$db['host'],
            'PHINX_DB_PORT' => (string)$db['port'],
            'PHINX_DB_NAME' => (string)$db['name'],
            'PHINX_DB_USER' => (string)$db['user'],
            'PHINX_DB_PASS' => (string)$db['pass'],
            'PHINX_DB_CHARSET' => (string)$db['charset'],
            'PHINX_DB_PREFIX' => (string)$db['prefix'],
        ]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (!is_resource($proc)) {
            throw new ApiException('无法启动迁移子进程（proc_open 被禁用？）');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $lineBuffer = '';
        $start = time();
        $lastBeat = time();
        $exit = -1;
        $emit = static function (string $chunk) use (&$output, &$lineBuffer, $onLine): void {
            $output .= $chunk;
            if ($onLine === null) {
                return;
            }
            $lineBuffer .= $chunk;
            while (($pos = strpos($lineBuffer, "\n")) !== false) {
                $line = rtrim(substr($lineBuffer, 0, $pos), "\r");
                $lineBuffer = substr($lineBuffer, $pos + 1);
                if ($line !== '') {
                    $onLine($line);
                }
            }
        };

        while (true) {
            $status = proc_get_status($proc);
            foreach ([1, 2] as $fd) {
                $chunk = stream_get_contents($pipes[$fd]);
                if ($chunk !== false && $chunk !== '') {
                    $emit($chunk);
                }
            }
            if (!$status['running']) {
                $exit = (int)$status['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                $emit("\n[迁移子进程超时（" . $timeout . "s），已终止]\n");
                $exit = -1;
                break;
            }
            if ($heartbeat !== null && time() - $lastBeat >= 60) {
                $lastBeat = time();
                $heartbeat();
            }
            usleep(100000);
        }
        // 收尾读取残留输出
        foreach ([1, 2] as $fd) {
            $chunk = stream_get_contents($pipes[$fd]);
            if ($chunk !== false && $chunk !== '') {
                $emit($chunk);
            }
            fclose($pipes[$fd]);
        }
        if ($lineBuffer !== '' && $onLine !== null) {
            $onLine(rtrim($lineBuffer, "\r"));
        }
        proc_close($proc);
        return ['exit' => $exit, 'log' => $output, 'applied' => []];
    }

    // ───────────────────────────── 锁（Redis NX，不可用时降级文件锁） ─────────────────────────────

    private static function acquireLock(): array
    {
        $token = bin2hex(random_bytes(8));
        try {
            if (Redis::set(self::LOCK_KEY, $token, 'EX', self::LOCK_TTL, 'NX')) {
                return ['type' => 'redis', 'token' => $token];
            }
            throw new ApiException('数据库迁移正在进行中，请等待其结束后再试');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis 不可用（未安装/未配置/扩展缺失）：降级文件锁，覆盖单机安装与 CLI 场景
            $file = runtime_path() . '/phinx.lock';
            $fp = @fopen($file, 'c');
            if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
                if ($fp) {
                    fclose($fp);
                }
                throw new ApiException('数据库迁移正在进行中，请等待其结束后再试');
            }
            ftruncate($fp, 0);
            fwrite($fp, $token);
            fflush($fp);
            return ['type' => 'file', 'token' => $token, 'fp' => $fp];
        }
    }

    private static function releaseLock(array $lock): void
    {
        try {
            if ($lock['type'] === 'redis') {
                if ((string)Redis::get(self::LOCK_KEY) === $lock['token']) {
                    Redis::del(self::LOCK_KEY);
                }
            } elseif (isset($lock['fp']) && is_resource($lock['fp'])) {
                flock($lock['fp'], LOCK_UN);
                fclose($lock['fp']);
                @unlink(runtime_path() . '/phinx.lock');
            }
        } catch (\Throwable $e) {
            // 锁随 TTL/进程退出自动释放
        }
    }

    /** 长任务心跳续锁（仅 Redis 锁；文件锁随进程持有）。 */
    private static function heartbeat(array $lock): ?callable
    {
        if ($lock['type'] !== 'redis') {
            return null;
        }
        return static function () use ($lock): void {
            try {
                if ((string)Redis::get(self::LOCK_KEY) === $lock['token']) {
                    Redis::expire(self::LOCK_KEY, self::LOCK_TTL);
                }
            } catch (\Throwable $e) {
                // 忽略
            }
        };
    }

    // ───────────────────────────── 失败补记与降级执行 ─────────────────────────────

    /** 子进程非零退出：对"计划执行但未成功"的迁移写 FAILED 行（与现有 saveMigration 失败口径一致）。 */
    private static function recordFailures(string $app, array $before, array $after, array $map, array $context, array $result, array $db): void
    {
        try {
            $pdo = self::pdo($db);
            $table = self::ledgerTable($db);
            $appName = self::appName($app);
            $error = mb_substr(self::logTail($result['log']), 0, 450);
            foreach ($map as $meta) {
                $name = (string)($meta['original_name'] ?? '');
                if ($name === '' || in_array($name, $before, true) || in_array($name, $after, true)) {
                    continue;
                }
                $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE app_name = ? AND migration = ? AND delete_time IS NULL LIMIT 1");
                $stmt->execute([$appName, $name]);
                $id = $stmt->fetchColumn();
                if ($id) {
                    $pdo->prepare("UPDATE {$table} SET status = ?, error_message = ?, update_time = ? WHERE id = ?")
                        ->execute([Migration::STATUS_FAILED, $error, time(), (int)$id]);
                } else {
                    self::insertLedgerRow($pdo, $table, [
                        'app_name' => $appName,
                        'migration_type' => (int)($context['migration_type'] ?? Migration::TYPE_SYSTEM),
                        'version' => (string)($meta['version'] ?? ($context['to_version'] ?? '')),
                        'migration' => $name,
                        'phinx_version' => (int)($meta['phinx_version'] ?? 0),
                        'checksum' => (string)($meta['checksum'] ?? ''),
                        'from_version' => (string)($context['from_version'] ?? ''),
                        'to_version' => (string)($context['to_version'] ?? ''),
                        'batch' => (int)($context['batch'] ?? 0),
                        'status' => Migration::STATUS_FAILED,
                        'error_message' => $error,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Phinx] 失败补记异常：' . $e->getMessage());
        }
    }

    /** Phinx 未安装时的降级执行：需要 context['files']；台账直写，口径与 Adapter 一致。 */
    private static function legacyFallback(string $app, array $context, array $db, ?callable $onLine): array
    {
        $files = (array)($context['files'] ?? []);
        if (!$files) {
            throw new ApiException('Phinx 未安装且未提供兼容执行文件清单，无法执行迁移');
        }
        $line = static function (string $msg) use ($onLine): void {
            if ($onLine !== null) {
                $onLine($msg);
            }
        };
        $line('[兼容模式] Phinx 尚未安装，本次使用 legacy 执行器（依赖安装后自动切换）');
        $scope = $app === 'base' ? self::SCOPE_BASE : self::SCOPE_PLUGIN;
        $plan = SqlMigrationWrapper::plan($files, $scope);
        $pdo = self::pdo($db);
        $table = self::ledgerTable($db);
        $appName = self::appName($app);
        $batch = self::nextBatch($app, $context, $db);
        $applied = [];
        $exit = 0;
        foreach ($plan as $item) {
            try {
                DatabaseService::executeSqlFile($item['sql_path'], (array)($context['replacements'] ?? []));
                self::upsertLedgerRow($pdo, $table, $appName, $item, $context, $batch, Migration::STATUS_SUCCESS, '');
                $applied[] = $item['original_name'];
                $line('迁移执行成功：' . $item['original_name']);
            } catch (\Throwable $e) {
                self::upsertLedgerRow($pdo, $table, $appName, $item, $context, $batch, Migration::STATUS_FAILED, mb_substr($e->getMessage(), 0, 450));
                $line('【失败】' . $item['original_name'] . '：' . $e->getMessage());
                $exit = 1;
                break;
            }
        }
        return ['exit' => $exit, 'log' => implode("\n", $applied), 'applied' => $applied];
    }

    // ───────────────────────────── 内部工具 ─────────────────────────────

    private static function assertApp(string $app): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $app)) {
            throw new ApiException('非法应用标识：' . $app);
        }
    }

    /** CLI/台账用的 app_name：基座在台账中归属 atrman（与现有记录一致），phinx 隔离键用 base。 */
    private static function appName(string $app): string
    {
        return $app === 'base' ? self::BASE_APP : $app;
    }

    private static function dbParams(array $db): array
    {
        $prefix = (string)(config('think-orm.connections.mysql.prefix') ?: env('DB_PREFIX', 'am_'));
        return [
            'host' => (string)($db['host'] ?? env('DB_HOSTNAME', '127.0.0.1')),
            'port' => (string)($db['port'] ?? env('DB_PORT', '3306')),
            'name' => (string)($db['name'] ?? env('DB_DATABASE', '')),
            'user' => (string)($db['user'] ?? env('DB_USERNAME', '')),
            'pass' => (string)($db['pass'] ?? env('DB_PASSWORD', '')),
            'charset' => (string)($db['charset'] ?? env('DB_CHARSET', 'utf8mb4')),
            'prefix' => (string)($db['prefix'] ?? $prefix),
        ];
    }

    private static function pdo(array $db): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        return new \PDO($dsn, $db['user'], $db['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    private static function ledgerTable(array $db): string
    {
        return $db['prefix'] . 'system_migration';
    }

    private static function manifestVersion(): string
    {
        $manifest = json_decode((string)@file_get_contents(base_path() . '/manifest.json'), true);
        return (string)($manifest['version'] ?? '1.0.0');
    }

    /** 已成功/已标记的迁移名集合（台账口径）。 */
    private static function appliedNames(string $app, array $db): array
    {
        $pdo = self::pdo($db);
        $stmt = $pdo->prepare('SELECT migration FROM ' . self::ledgerTable($db) . ' WHERE app_name = ? AND status IN (0,1) AND delete_time IS NULL');
        $stmt->execute([self::appName($app)]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function nextBatch(string $app, array $context, array $db): int
    {
        $pdo = self::pdo($db);
        $stmt = $pdo->prepare('SELECT MAX(batch) FROM ' . self::ledgerTable($db) . ' WHERE app_name = ? AND migration_type = ?');
        $stmt->execute([self::appName($app), (int)($context['migration_type'] ?? Migration::TYPE_SYSTEM)]);
        return (int)$stmt->fetchColumn() + 1;
    }

    /** DSL 迁移的 ledger_map：类名 → 源文件哈希（语义版本由 context.to_version 兜底）。 */
    public static function dslMap(array $paths): array
    {
        $map = [];
        foreach ($paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                if (preg_match('/^\d{14}_([A-Za-z0-9_]+)\.php$/', basename($file), $m)) {
                    $map[$m[1]] = [
                        'original_name' => $m[1],
                        'checksum' => hash_file('sha256', $file) ?: '',
                        'phinx_version' => (int)substr(basename($file), 0, 14),
                    ];
                }
            }
        }
        return $map;
    }

    private static function insertLedgerRow(\PDO $pdo, string $table, array $data): void
    {
        $data += [
            'from_version' => '',
            'to_version' => '',
            'batch' => 0,
            'error_message' => '',
            'executed_at' => date('Y-m-d H:i:s'),
            'create_time' => time(),
            'update_time' => time(),
        ];
        $columns = implode(', ', array_map(static fn($c) => '`' . $c . '`', array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        // INSERT IGNORE：唯一键冲突忽略，基线标记并发安全（status 查询不加锁）
        $pdo->prepare("INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})")->execute(array_values($data));
    }

    private static function upsertLedgerRow(\PDO $pdo, string $table, string $appName, array $item, array $context, int $batch, int $status, string $error): void
    {
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE app_name = ? AND migration = ? AND delete_time IS NULL LIMIT 1");
        $stmt->execute([$appName, $item['original_name']]);
        $id = $stmt->fetchColumn();
        $data = [
            'migration_type' => (int)($context['migration_type'] ?? Migration::TYPE_SYSTEM),
            'version' => $item['semantic_version'],
            'phinx_version' => (int)$item['phinx_version'],
            'checksum' => $item['checksum'],
            'from_version' => (string)($context['from_version'] ?? ''),
            'to_version' => (string)($context['to_version'] ?? ''),
            'batch' => $batch,
            'status' => $status,
            'error_message' => $error,
            'executed_at' => date('Y-m-d H:i:s'),
            'update_time' => time(),
        ];
        if ($id) {
            $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($data)));
            $pdo->prepare("UPDATE {$table} SET {$sets} WHERE id = ?")->execute([...array_values($data), (int)$id]);
        } else {
            self::insertLedgerRow($pdo, $table, $data + [
                'app_name' => $appName,
                'migration' => $item['original_name'],
                'create_time' => time(),
            ]);
        }
    }

    public static function logTail(string $log, int $lines = 8): string
    {
        $rows = array_values(array_filter(explode("\n", trim($log)), static fn($l) => trim($l) !== ''));
        return implode("\n", array_slice($rows, -$lines));
    }
}