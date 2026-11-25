<?php

namespace app\database;

use app\exception\ApiException;

/**
 * 把 legacy .sql（基座升级脚本 / 插件升级 SQL / install.sql）包装成 Phinx 可识别的 PHP 迁移。
 * 时间戳与类名由源文件确定性派生，重复包装结果恒定，台账判重幂等。
 * 生成的迁移只实现 up()（不可逆，回滚走基座快照体系）；迁移类在子进程运行，只准用纯静态工具与 __DIR__ 相对路径。
 */
class SqlMigrationWrapper
{
    /**
     * 计算包装计划（不写文件）。
     *
     * @param array $files [{sql_path, semantic_version, original_name}]
     * @param string $scope '20'=基座 '21'=插件
     * @return array [{phinx_version, class_name, original_name, sql_path, semantic_version, checksum, seq}]
     */
    public static function plan(array $files, string $scope): array
    {
        $files = array_values(array_filter($files, static fn($f) => is_array($f) && !empty($f['sql_path'])));
        usort($files, static function (array $a, array $b): int {
            $compare = version_compare((string)$a['semantic_version'], (string)$b['semantic_version']);
            return $compare !== 0 ? $compare : strcmp((string)$a['original_name'], (string)$b['original_name']);
        });

        $plan = [];
        $seqByVersion = [];
        foreach ($files as $file) {
            $version = (string)$file['semantic_version'];
            if ($version === '') {
                throw new ApiException('迁移缺少语义版本，无法包装：' . ($file['original_name'] ?? $file['sql_path']));
            }
            $seq = ($seqByVersion[$version] ?? 0) + 1;
            $seqByVersion[$version] = $seq;
            $originalName = (string)($file['original_name'] ?? basename($file['sql_path']));
            $plan[] = [
                'phinx_version' => self::timestamp($scope, $version, $seq),
                'class_name' => self::className($originalName),
                'original_name' => $originalName,
                'sql_path' => (string)$file['sql_path'],
                'semantic_version' => $version,
                'checksum' => is_file($file['sql_path']) ? (hash_file('sha256', $file['sql_path']) ?: '') : '',
                'seq' => $seq,
            ];
        }
        return $plan;
    }

    /**
     * 生成包装迁移到 $targetDir：复制 SQL（占位符在此时替换）+ 写 PHP 迁移类。
     *
     * @param array $files [{sql_path, semantic_version, original_name}]
     * @param string $prefix 表前缀（__PREFIX__ / {{DB_PREFIX}} 替换值）
     * @param array $replacements 附加占位符（{{APP_ID}} / {{APP_NAME}} / {{APP_ENTRY_PATH}}）
     * @return array ledger_map：class_name => {original_name, version, checksum, phinx_version}
     */
    public static function wrap(array $files, string $targetDir, string $scope, string $prefix, array $replacements = []): array
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new ApiException('迁移包装目录创建失败：' . $targetDir);
        }
        $map = [];
        $usedNames = [];
        foreach (self::plan($files, $scope) as $item) {
            if (!is_file($item['sql_path'])) {
                throw new ApiException('迁移源文件不存在：' . $item['sql_path']);
            }
            $sql = (string)file_get_contents($item['sql_path']);
            $sql = str_replace(['__PREFIX__', '{{DB_PREFIX}}'], $prefix, $sql);
            if ($replacements) {
                $sql = strtr($sql, $replacements);
            }

            // 复制后的 SQL 文件名（同名碰撞时追加短哈希）
            $copyName = $item['original_name'];
            if (isset($usedNames[$copyName])) {
                $copyName = pathinfo($copyName, PATHINFO_FILENAME) . '_' . substr(sha1($item['sql_path']), 0, 6) . '.sql';
            }
            $usedNames[$copyName] = true;
            if (file_put_contents($targetDir . '/' . $copyName, $sql) === false) {
                throw new ApiException('迁移 SQL 复制失败：' . $copyName);
            }

            $className = $item['class_name'];
            $code = self::renderMigration($className, $copyName);
            $file = $targetDir . '/' . $item['phinx_version'] . '_' . $className . '.php';
            if (file_put_contents($file, $code) === false) {
                throw new ApiException('包装迁移写入失败：' . $file);
            }

            $map[$className] = [
                'original_name' => $item['original_name'],
                'version' => $item['semantic_version'],
                'checksum' => $item['checksum'],
                'phinx_version' => $item['phinx_version'],
            ];
        }
        return $map;
    }

    /**
     * 确定性时间戳（14 位）：scope(2) + major(2) + minor(2) + patch(2) + seq(6)。
     * 例：基座 v1.0.0 第 1 个 → 20010000000001；插件 v1.2.0 第 1 个 → 21010200000001。
     */
    public static function timestamp(string $scope, string $semanticVersion, int $seq = 1): string
    {
        $parts = array_pad(explode('.', $semanticVersion), 3, '0');
        [$major, $minor, $patch] = array_map('intval', array_slice($parts, 0, 3));
        if ($major > 99 || $minor > 99 || $patch > 99 || $major < 0 || $minor < 0 || $patch < 0) {
            throw new ApiException('语义版本超出编码范围（各段须 0~99）：' . $semanticVersion);
        }
        if ($seq < 1 || $seq > 999999) {
            throw new ApiException('迁移序号超出范围：' . $seq);
        }
        return sprintf('%s%02d%02d%02d%06d', $scope, $major, $minor, $patch, $seq);
    }

    /** 类名：Wrap + 文件名 CamelCase 化 + sha1 短哈希（防碰撞、确定性）。 */
    public static function className(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $parts = preg_split('/[^a-zA-Z0-9]+/', $base) ?: [];
        $camel = implode('', array_map(static fn($p) => ucfirst(strtolower($p)), array_filter($parts)));
        return 'Wrap' . $camel . ucfirst(substr(sha1($originalName), 0, 6));
    }

    /** 从文件名提取语义版本（取最后一个 x.y.z 匹配，兼容 upgrade_v1.0.6_to_v1.0.7.sql）。 */
    public static function parseVersion(string $filename): string
    {
        if (preg_match_all('/\d+\.\d+\.\d+/', $filename, $matches) && $matches[0]) {
            return (string)end($matches[0]);
        }
        return '';
    }

    /** 生成只含 up() 的包装迁移类源码。 */
    private static function renderMigration(string $className, string $sqlFileName): string
    {
        $exportName = var_export($sqlFileName, true);
        return <<<PHP
<?php

use Phinx\Migration\AbstractMigration;

/** 由 SqlMigrationWrapper 自动生成（勿手改）：包装 legacy SQL，只实现 up()，回滚不可逆。 */
class {$className} extends AbstractMigration
{
    private const SQL_FILE = __DIR__ . '/' . {$exportName};

    public function up(): void
    {
        \$sql = (string)file_get_contents(self::SQL_FILE);
        foreach (\\app\\service\\system\\DatabaseService::splitSqlStatements(\$sql) as \$stmt) {
            \$this->execute(\$stmt);
        }
    }
}

PHP;
    }
}