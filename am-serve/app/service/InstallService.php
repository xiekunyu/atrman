<?php

namespace app\service;

use app\exception\ApiException;
use app\utils\Hashids;
use support\Db;

/**
 * 系统安装服务
 * 
 * 封装所有安装业务逻辑：环境检测、连接测试、SQL 导入、初始数据生成、配置写入
 */
class InstallService
{
    /**
     * 创建 PDO 连接（统一 DSN 构建和连接选项）
     */
    private static function createPdo(string $host, string $port, string $username, string $password, string $database = '', string $charset = 'utf8mb4', int $timeout = 5): \PDO
    {
        $dsn = $database
            ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset)
            : sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset);
        return new \PDO($dsn, $username, $password, [
            \PDO::ATTR_TIMEOUT => $timeout,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * 检查数据库是否存在
     */
    private static function databaseExists(\PDO $pdo, string $database): bool
    {
        $stmt = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($database));
        return $stmt->rowCount() > 0;
    }

    /**
     * 获取指定前缀的表列表
     */
    private static function getTablesWithPrefix(\PDO $pdo, string $database, string $prefix = 'am_'): array
    {
        $pdo->exec("USE `{$database}`");
        $tables = $pdo->query("SHOW TABLES LIKE '{$prefix}%'")->fetchAll(\PDO::FETCH_COLUMN);
        return $tables ?: [];
    }

    /**
     * 检测系统是否已安装（双重锁 + 数据兜底）
     *
     * 锁文件在 runtime/ 下且被 gitignore，镜像发布、目录清理都会让它消失；只看文件锁会把
     * 已上线的站点重新判定成「未安装」，从而对外开放 /api/install/execute 重建管理员并改写 .env。
     * 所以双重锁不过时补查一次库：已有账号即视为已安装。
     */
    public static function isInstalled(): bool
    {
        $fileLocked = is_file(runtime_path() . '/install.lock');
        $envInstalled = env('APP_INSTALL', false) === true
                     || env('APP_INSTALL', 'false') === 'true';
        if ($fileLocked && $envInstalled) {
            return true;
        }

        return self::hasSeededDatabase();
    }

    /**
     * 库内是否已存在账号数据。仅在双重锁未通过时执行，正常已安装站点走不到这里。
     *
     * 判定失败时的口径决定「安装路由是否对外开放」，所以必须偏保守：
     * 连不上库时，只要 .env 里已写过 DB_DATABASE（说明本机装过一次），一律按「已安装」处理 ——
     * 误判成未安装会把 /api/install/execute 重新开放，任何人都能借此重建管理员并改写 .env。
     * 真正的首次部署没有 DB_DATABASE，仍按未安装放行安装向导。
     */
    private static function hasSeededDatabase(): bool
    {
        try {
            return Db::name('system_user')->limit(1)->value('id') !== null;
        } catch (\Throwable $e) {
            if (self::isMissingTableError($e)) {
                // 连上了但表不存在：确实是未播种的新库
                return false;
            }
            return (string)env('DB_DATABASE', '') !== '';
        }
    }

    /** 是否为「表不存在」类错误（MySQL 1146 / SQLSTATE 42S02），与之相对的是连接类错误。 */
    private static function isMissingTableError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains((string)$e->getCode(), '42S02')
            || str_contains($message, 'base table or view not found')
            || str_contains($message, "doesn't exist");
    }

    /**
     * 环境检测
     * 
     * 采用数组配置驱动，新增检测项只需在对应数组中添加条目
     */
    public static function checkEnvironment(): array
    {
        $items = [];
        $allPassed = true;

        // ── PHP 版本 ──
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');
        $items[] = [
            'category' => 'php_version',
            'name' => 'PHP 版本',
            'required' => '>= 8.2',
            'current' => $phpVersion,
            'passed' => $phpOk,
            'level' => 'required',
        ];
        if (!$phpOk) $allPassed = false;

        // ── PHP 扩展 ──
        $extensions = [
            ['name' => 'pdo_mysql',  'desc' => 'MySQL 数据库连接驱动，ORM 底层依赖'],
            ['name' => 'redis',      'desc' => 'Redis 连接驱动，缓存/队列/会话依赖'],
            ['name' => 'openssl',    'desc' => '加密解密，JWT 签名、密码哈希依赖'],
            ['name' => 'zip',        'desc' => 'ZipArchive 扩展，备份打包/插件安装依赖'],
            ['name' => 'gd',         'desc' => '图片处理，验证码生成、缩略图裁剪'],
            ['name' => 'fileinfo',   'desc' => '文件 MIME 类型检测，文件上传安全校验'],
        ];

        foreach ($extensions as $ext) {
            $loaded = extension_loaded($ext['name']);
            $items[] = [
                'category' => 'extension',
                'name' => $ext['name'],
                'desc' => $ext['desc'],
                'required' => '需要安装',
                'current' => $loaded ? '已安装' : '未安装',
                'passed' => $loaded,
                'level' => 'required',
            ];
            if (!$loaded && !in_array($ext['name'], [ 'gd', 'fileinfo'])) {
                $allPassed = false;
            }
        }

        // ── PHP 函数 ──
        $functions = [
            ['name' => 'exec',                  'desc' => '执行外部命令，mysqldump 数据库导出/备份'],
            ['name' => 'shell_exec',            'desc' => 'Shell 命令执行，系统信息读取（CPU/内存/磁盘）'],
            ['name' => 'putenv',                'desc' => '设置环境变量，运行时动态配置 .env 变量'],
            ['name' => 'proc_open',             'desc' => '进程控制，带超时的命令执行（如数据库导入）'],
            ['name' => 'pcntl_fork',            'desc' => '多进程派生，Webman 工作进程管理'],
            ['name' => 'pcntl_wait',            'desc' => '等待子进程退出，进程回收防止僵尸进程'],
            ['name' => 'pcntl_signal',          'desc' => '信号处理，优雅重启/停止（SIGTERM/SIGINT）'],
            ['name' => 'pcntl_signal_dispatch', 'desc' => '信号分发，触发已注册的信号处理器'],
            ['name' => 'pcntl_alarm',           'desc' => '定时器信号，超时任务调度'],
        ];

        $requiredFunctions = ['exec', 'shell_exec', 'putenv', 'proc_open', 'pcntl_fork', 'pcntl_wait', 'pcntl_signal', 'pcntl_signal_dispatch', 'pcntl_alarm'];

        foreach ($functions as $func) {
            $available = function_exists($func['name']);
            $level = in_array($func['name'], $requiredFunctions) ? 'required' : 'recommended';
            $items[] = [
                'category' => 'function',
                'name' => $func['name'],
                'desc' => $func['desc'],
                'required' => '可用',
                'current' => $available ? '可用' : '不可用（已被禁用或未安装）',
                'passed' => $available,
                'level' => $level,
            ];
            if (!$available && $level === 'required') {
                $allPassed = false;
            }
        }

        // ── 目录权限 ──
        $dirs = [
            ['path' => runtime_path(),           'name' => 'runtime/',       'desc' => '缓存、日志、锁文件、临时文件存储'],
            ['path' => public_path(),            'name' => 'public/',        'desc' => '文件上传、静态资源存储'],
            ['path' => base_path() . '/.env',   'name' => '.env',          'desc' => '安装时写入环境配置文件', 'isFile' => true],
        ];

        foreach ($dirs as $dir) {
            $isFile = $dir['isFile'] ?? false;
            if ($isFile) {
                // .env 文件：如果存在检查可写，不存在检查父目录可写
                $writable = is_file($dir['path']) ? is_writable($dir['path']) : is_writable(base_path());
                $current = $writable ? '可写' : '不可写';
            } else {
                $writable = is_dir($dir['path']) && is_writable($dir['path']);
                $current = $writable ? '可写' : (is_dir($dir['path']) ? '不可写' : '目录不存在');
            }
            $items[] = [
                'category' => 'permission',
                'name' => $dir['name'],
                'desc' => $dir['desc'],
                'required' => '可写',
                'current' => $current,
                'passed' => $writable,
                'level' => 'required',
            ];
            if (!$writable) {
                $allPassed = false;
            }
        }

        return [
            'passed' => $allPassed,
            'items' => $items,
        ];
    }

    /**
     * 测试数据库连接
     */
    public static function testDatabaseConnection(array $config): array
    {
        try {
            $pdo = self::createPdo(
                $config['host'],
                $config['port'] ?? '3306',
                $config['username'],
                $config['password'],
                '',
                $config['charset'] ?? 'utf8mb4'
            );

            // 获取 MySQL 版本
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();

            // 检查数据库是否存在
            $dbName = $config['database'];
            $dbExists = self::databaseExists($pdo, $dbName);

            // 如果数据库存在，检查已有表
            $existingTables = 0;
            $prefix = $config['prefix'] ?? 'am_';
            if ($dbExists) {
                $tables = self::getTablesWithPrefix($pdo, $dbName, $prefix);
                $existingTables = count($tables);
            }

            return [
                'success' => true,
                'message' => '连接成功',
                'data' => [
                    'mysql_version' => $version,
                    'database_exists' => $dbExists,
                    'existing_tables' => $existingTables,
                ],
            ];
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'message' => '数据库连接失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 测试 Redis 连接
     */
    public static function testRedisConnection(array $config): array
    {
        try {
            if (!class_exists(\Redis::class)) {
                return ['success' => false, 'message' => 'Redis 扩展未安装'];
            }

            $redis = new \Redis();
            $redis->connect($config['host'], (int)($config['port'] ?? 6379), 5);

            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }

            $info = $redis->info('server');
            $redis->ping();

            return [
                'success' => true,
                'message' => '连接成功',
                'data' => [
                    'redis_version' => $info['redis_version'] ?? '未知',
                ],
            ];
        } catch (\RedisException $e) {
            return [
                'success' => false,
                'message' => 'Redis 连接失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查数据库中是否已有同前缀表
     */
    public static function checkDatabaseExists(array $config): array
    {
        try {
            $pdo = self::createPdo(
                $config['host'],
                $config['port'] ?? '3306',
                $config['username'],
                $config['password']
            );

            $dbName = $config['database'];
            if (!self::databaseExists($pdo, $dbName)) {
                return ['database_exists' => false, 'table_count' => 0, 'tables' => []];
            }

            $prefix = $config['prefix'] ?? 'am_';
            $tables = self::getTablesWithPrefix($pdo, $dbName, $prefix);

            return [
                'database_exists' => true,
                'table_count' => count($tables),
                'tables' => $tables,
            ];
        } catch (\PDOException $e) {
            return ['database_exists' => false, 'table_count' => 0, 'tables' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 执行完整安装流程
     */
    public static function executeInstall(array $config): array
    {
        try {
            // 防重复安装（接口经注解路由常驻注册，已安装时必须拦截）
            if (self::isInstalled()) {
                return [
                    'success' => false,
                    'message' => '系统已安装，请勿重复执行安装',
                ];
            }

            // 0. 解析后台入口文件名（提前校验，避免安装中途失败）
            $entryFile = self::resolveEntryFile($config['entry_file'] ?? '');

            // 1. 创建数据库连接（自动创建数据库）
            $pdo = self::createDatabaseConnection($config);

            // 2. 导入 install.sql（建表）
            self::importSqlFile($pdo, base_path() . '/install/install.sql');

            // 3. 导入 seed.sql（菜单、字典、配置等初始数据）
            self::importSqlFile($pdo, base_path() . '/install/seed.sql');

            // 4. 创建超级管理员
            self::createSuperAdmin($pdo, $config);

            // 5. 生成 JWT 密钥：access 与 refresh 各一把。同一把密钥签两类令牌时，
            // refresh 令牌可以直接当 access 用（反之亦然），两类令牌的有效期与语义差异形同不存在。
            $jwtAccessKey = bin2hex(random_bytes(16));
            $jwtRefreshKey = bin2hex(random_bytes(16));

            // 6. 写入 .env 文件
            self::writeEnvFile($config, $jwtAccessKey, $jwtRefreshKey, $entryFile);

            // 6.5 应用入口设置：入口文件改名 + 安装页更名（防再次访问，可恢复）
            self::applyEntryFile($entryFile);

            // 7. 写入安装锁
            self::createLockFile($config);

            return [
                'success' => true,
                'message' => '安装成功',
                'data' => [
                    'app_url' => $config['app_url'],
                    'entry_file' => $entryFile,
                    'entry_url' => rtrim($config['app_url'], '/') . '/' . $entryFile,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => '安装失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 创建数据库连接（如果数据库不存在则自动创建）
     */
    private static function createDatabaseConnection(array $config): \PDO
    {
        $pdo = self::createPdo(
            $config['db_host'],
            $config['db_port'] ?? '3306',
            $config['db_user'],
            $config['db_password'],
            '',
            $config['db_charset'] ?? 'utf8mb4',
            10
        );

        // 创建数据库（如果不存在）
        $dbName = $config['db_name'];
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET {$config['db_charset']} COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        return $pdo;
    }

    /**
     * 导入 SQL 文件
     */
    private static function importSqlFile(\PDO $pdo, string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("SQL 文件不存在：{$filePath}");
        }

        $sql = file_get_contents($filePath);
        if (empty($sql)) {
            throw new \RuntimeException("SQL 文件为空：{$filePath}");
        }

        // 按分号拆分 SQL 语句，逐条执行
        // 先移除注释
        $sql = self::removeSqlComments($sql);

        // 拆分为多条语句
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        );

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
    }

    /**
     * 移除 SQL 注释
     */
    private static function removeSqlComments(string $sql): string
    {
        // 移除单行注释 -- 和 #
        $sql = preg_replace('/^(?:--|#)\s.*$/m', '', $sql);
        // 移除多行注释 /* ... */
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        return $sql;
    }

    /**
     * 创建超级管理员账号
     */
    private static function createSuperAdmin(\PDO $pdo, array $config): void
    {
        $prefix = $config['db_prefix'] ?? 'am_';
        $now = time();
        $salt = substr(bin2hex(random_bytes(2)), 0, 4); // 4 位随机 salt
        $password = passwordHash($config['admin_password'], $salt);
        $sid = Hashids::getSid('sys');

        // 创建超级管理员角色
        $pdo->exec("INSERT INTO `{$prefix}system_role` (`name`, `code`, `level`, `data_scope`, `sort`, `status`, `created_by`, `create_time`, `update_time`) VALUES ('超级管理员', 'super_admin', 99, 1, 0, 1, 1, {$now}, {$now})");
        $roleId = (int)$pdo->lastInsertId();

        // 创建管理员账号
        $username = $pdo->quote($config['admin_username']);
        $nickname = $pdo->quote($config['admin_username']);
        $pdo->exec("INSERT INTO `{$prefix}system_user` (`sid`, `username`, `password`, `nickname`, `salt`, `status`, `created_by`, `create_time`, `update_time`) VALUES ('{$sid}', {$username}, '{$password}', {$nickname}, '{$salt}', 1, 1, {$now}, {$now})");
        $userId = (int)$pdo->lastInsertId();

        // 关联管理员与角色
        $pdo->exec("INSERT INTO `{$prefix}system_user_role` (`user_id`, `role_id`) VALUES ({$userId}, {$roleId})");

        // 给超级管理员分配所有菜单权限
        $menuIds = $pdo->query("SELECT `id` FROM `{$prefix}system_menu`")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($menuIds as $menuId) {
            $pdo->exec("INSERT INTO `{$prefix}system_role_menu` (`role_id`, `menu_id`) VALUES ({$roleId}, {$menuId})");
        }
    }

    /**
     * 解析后台入口文件名
     *
     * @param string $entryFile 用户提交的入口文件名：空 = 随机生成，index.html = 保持默认
     */
    private static function resolveEntryFile(string $entryFile): string
    {
        $entryFile = trim($entryFile);

        // 未填写：随机生成 manage_xxxxxxxx.html
        if ($entryFile === '') {
            do {
                $entryFile = 'manage_' . bin2hex(random_bytes(4)) . '.html';
            } while (is_file(public_path() . '/' . $entryFile));
            return $entryFile;
        }

        // 保持默认
        if ($entryFile === 'index.html') {
            return 'index.html';
        }

        // 自定义：字母开头，仅字母数字下划线连字符，最长 50 字符
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}\.html$/', $entryFile)) {
            throw new \RuntimeException('入口文件名格式不正确（字母开头，仅支持字母、数字、_、-，以 .html 结尾）');
        }
        if (in_array(strtolower($entryFile), ['install.html'], true)) {
            throw new \RuntimeException('入口文件名不能使用保留名称 install.html');
        }
        if (is_file(public_path() . '/' . $entryFile)) {
            throw new \RuntimeException('入口文件名与已有文件冲突，请更换');
        }

        return $entryFile;
    }

    /**
     * 应用入口设置：入口文件改名 + 安装页更名为 .bak
     */
    private static function applyEntryFile(string $entryFile): void
    {
        // 入口文件改名
        if ($entryFile !== 'index.html') {
            $src = public_path() . '/index.html';
            $dst = public_path() . '/' . $entryFile;
            if (is_file($src)) {
                if (!@rename($src, $dst)) {
                    throw new \RuntimeException('入口文件改名失败，请检查 public 目录权限');
                }
            } elseif (!is_file($dst)) {
                // src 不存在且 dst 也不存在：从未构建过前端
                // （src 不存在但 dst 存在 = 重装且未重新构建，幂等跳过）
                throw new \RuntimeException('入口文件 public/index.html 不存在，请先执行前端构建');
            }
        }

        // 安装页更名（重新安装时将其改回 install.html 即可）
        $install = public_path() . '/install.html';
        if (is_file($install)) {
            @rename($install, $install . '.bak');
        }
    }

    /**
     * 写入 .env 文件
     */
    private static function writeEnvFile(array $config, string $jwtAccessKey, string $jwtRefreshKey, string $entryFile = 'index.html'): void
    {
        $envContent = <<<ENV
# APP 配置
APP_DEBUG = {$config['app_debug']}
APP_TIMEZONE = {$config['app_timezone']}
APP_LANGUAGE = zh-CN
APP_NAME = Atrman
APP_INSTALL = true

# 后台入口文件（安装生成的安全入口名，如 manage_XXXX.html；会员中心管理员登录后据此拼域名直达后台；默认 index.html）
APP_ENTRY_FILE = {$entryFile}

# HTTP worker 数：留空自动取 min(CPU 核数, 4)；限核容器/小内存机建议显式写 1~2，改完重启
WORKER_COUNT =

# 数据库配置
DB_TYPE = mysql
DB_HOSTNAME = {$config['db_host']}
DB_DATABASE = {$config['db_name']}
DB_USERNAME = {$config['db_user']}
DB_PASSWORD = {$config['db_password']}
DB_PORT = {$config['db_port']}
DB_CHARSET = {$config['db_charset']}
DB_PREFIX = {$config['db_prefix']}

# redis配置
CACHE_DRIVE = redis
REDIS_HOST = {$config['redis_host']}
REDIS_PORT = {$config['redis_port']}
REDIS_PASSWORD = {$config['redis_password']}
REDIS_PREFIX = atrman:

# JWT 配置
JWT_ALG = HS256
JWT_ACCESS_KEY = {$jwtAccessKey}
# 访问令牌 3 天：装完机不要把窗口开到 7 天，refresh 令牌仍是 7 天，续期由前端无感完成
JWT_ACCESS_EXP = 259200
JWT_REFRESH_KEY = {$jwtRefreshKey}
JWT_REFRESH_EXP = 604800

# SSO 接管：true=三方 OAuth 授权未登录流量改走会员中心登录页；false/不写=原生登录（默认）
SSO_AMWEB_ENABLED = false

# SSO 接管目标：会员中心插件标识（入口地址由该插件 manifest 的 entry 自动解析，默认 /app/{name}）
SSO_MEMBER_APP = am_member
ENV;

        $envPath = base_path() . '/.env';
        $result = @file_put_contents($envPath, $envContent . "\n");
        if ($result === false) {
            throw new \RuntimeException('.env 文件写入失败，请检查目录权限');
        }
        @chmod($envPath, 0644);
    }

    /**
     * 写入安装锁文件
     */
    private static function createLockFile(array $config): void
    {
        $lockData = json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'installed_ip' => request()->getRealIp() ?: '127.0.0.1',
            'app_version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $lockPath = runtime_path() . '/install.lock';
        $result = @file_put_contents($lockPath, $lockData);
        if ($result === false) {
            throw new \RuntimeException('安装锁文件写入失败，请检查 runtime 目录权限');
        }
    }
}
