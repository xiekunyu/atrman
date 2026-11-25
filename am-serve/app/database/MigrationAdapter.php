<?php

namespace app\database;

use Phinx\Db\Adapter\AdapterInterface;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\MigrationInterface;
use app\service\system\DatabaseService;
use PDO;

/**
 * Phinx 单台账适配器：直接读写 system_migration（不引入 phinxlog），并在 execute/query 前做 SQL 安全拦截。
 * 由 phinx-runner.php 启动时经 AdapterFactory::registerAdapter('mysql', ...) 注册。
 * 运行于 phinx 子进程：只准用 DatabaseService 纯静态方法与自身 PDO 连接，不得依赖 webman 运行时。
 */
class MigrationAdapter extends MysqlAdapter
{
    /** 重写以拦截父类自动给台账表补 migration_name/breakpoint 列的行为，改为幂等确保本方台账结构。 */
    public function setConnection(PDO $connection): AdapterInterface
    {
        $this->connection = $connection;
        $this->ensureLedgerSchema();
        return $this;
    }

    /** 幂等确保 system_migration 结构（含 phinx_version 列）。 */
    public function createSchemaTable(): void
    {
        $this->ensureLedgerSchema();
    }

    /** 已执行版本：本应用 status IN (0,1) 且未软删，按 phinx_version 升序；FAILED(-1) 允许重试。 */
    public function getVersionLog(): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = %s AND %s IN (0, 1) AND %s IS NULL ORDER BY %s ASC',
            $this->quoteTableName($this->getSchemaTableName()),
            $this->quoteColumnName('app_name'),
            $this->getConnection()->quote($this->appName()),
            $this->quoteColumnName('status'),
            $this->quoteColumnName('delete_time'),
            $this->quoteColumnName('phinx_version')
        );
        $result = [];
        foreach ($this->fetchAll($sql) as $row) {
            $version = (int)$row['phinx_version'];
            $result[$version] = [
                'version' => $version,
                'migration_name' => (string)$row['migration'],
                'start_time' => (string)($row['executed_at'] ?? ''),
                'end_time' => (string)($row['executed_at'] ?? ''),
                'breakpoint' => 0,
                'checksum' => (string)($row['checksum'] ?? ''),
            ];
        }
        return $result;
    }

    /** UP 按 (app_name, migration) upsert 台账行（mark_mode 写 status=0）；DOWN 按 phinx_version 撤账。 */
    public function migrated(MigrationInterface $migration, string $direction, string $startTime, string $endTime): AdapterInterface
    {
        $pdo = $this->getConnection();
        $table = $this->quoteTableName($this->getSchemaTableName());
        $app = $this->appName();
        $phinxVersion = (int)$migration->getVersion();

        if (strcasecmp($direction, MigrationInterface::UP) !== 0) {
            $this->execute(sprintf(
                'DELETE FROM %s WHERE %s = %s AND %s = %d',
                $table,
                $this->quoteColumnName('app_name'),
                $pdo->quote($app),
                $this->quoteColumnName('phinx_version'),
                $phinxVersion
            ));
            return $this;
        }

        $map = (array)$this->getOption('ledger_map');
        $context = (array)$this->getOption('ledger_context');
        $className = $migration->getName();
        $meta = (array)($map[$className] ?? []);
        $migrationName = (string)($meta['original_name'] ?? $className);
        $status = $this->getOption('mark_mode') ? 0 : 1;
        $now = time();

        $values = [
            'app_name' => $app,
            'migration_type' => (int)($context['migration_type'] ?? 2),
            'version' => (string)($meta['version'] ?? ($context['to_version'] ?? '')),
            'migration' => $migrationName,
            'phinx_version' => $phinxVersion,
            'checksum' => (string)($meta['checksum'] ?? ''),
            'from_version' => (string)($context['from_version'] ?? ''),
            'to_version' => (string)($context['to_version'] ?? ''),
            'batch' => (int)($context['batch'] ?? 0),
            'status' => $status,
            'error_message' => '',
            'executed_at' => $endTime,
            'update_time' => $now,
        ];

        $existing = $this->fetchRow(sprintf(
            'SELECT %s FROM %s WHERE %s = %s AND %s = %s LIMIT 1',
            $this->quoteColumnName('id'),
            $table,
            $this->quoteColumnName('app_name'),
            $pdo->quote($app),
            $this->quoteColumnName('migration'),
            $pdo->quote($migrationName)
        ));

        if ($existing) {
            $sets = [];
            foreach ($values as $column => $value) {
                $sets[] = $this->quoteColumnName($column) . ' = ' . $this->sqlLiteral($value);
            }
            $this->execute(sprintf(
                'UPDATE %s SET %s WHERE %s = %d',
                $table,
                implode(', ', $sets),
                $this->quoteColumnName('id'),
                (int)$existing['id']
            ));
        } else {
            $values['create_time'] = $now;
            $columns = implode(', ', array_map(fn($c) => $this->quoteColumnName($c), array_keys($values)));
            $literals = implode(', ', array_map(fn($v) => $this->sqlLiteral($v), $values));
            $this->execute(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $columns, $literals));
        }

        return $this;
    }

    // 断点功能不启用

    public function toggleBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        return $this;
    }

    public function setBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        return $this;
    }

    public function unsetBreakpoint(MigrationInterface $migration): AdapterInterface
    {
        return $this;
    }

    public function resetAllBreakpoints(): int
    {
        return 0;
    }

    // SQL 安全拦截

    public function execute(string $sql, array $params = []): int
    {
        if ($params === []) {
            $this->assertSqlSafe($sql);
        }
        return parent::execute($sql, $params);
    }

    public function query(string $sql, array $params = []): mixed
    {
        if ($params === []) {
            $this->assertSqlSafe($sql);
        }
        return parent::query($sql, $params);
    }

    // ── 内部 ──

    private function appName(): string
    {
        return (string)$this->getOption('app_name');
    }

    private function assertSqlSafe(string $sql): void
    {
        foreach (DatabaseService::splitSqlStatements($sql) as $statement) {
            DatabaseService::assertSafeStatement($statement);
        }
    }

    private function sqlLiteral(mixed $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        return $this->getConnection()->quote((string)$value);
    }

    /** 表不存在则建完整结构（先于 InitialSchema 执行，install.sql 该表为 IF NOT EXISTS）；缺列则补列+索引。 */
    private function ensureLedgerSchema(): void
    {
        $pdo = $this->getConnection();
        $table = $this->getSchemaTableName();
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote(str_replace('_', '\\_', $table)))->fetchColumn();
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$quoted} (
                  `id` int NOT NULL AUTO_INCREMENT,
                  `app_name` varchar(64) NOT NULL COMMENT '应用标识，基座为atrman',
                  `migration_type` tinyint NOT NULL DEFAULT '1' COMMENT '迁移类型：1插件迁移，2系统迁移',
                  `version` varchar(50) NOT NULL DEFAULT '' COMMENT '迁移版本',
                  `migration` varchar(160) NOT NULL COMMENT '迁移文件名/类名',
                  `phinx_version` bigint NOT NULL DEFAULT '0' COMMENT 'Phinx确定性时间戳版本',
                  `checksum` varchar(64) NOT NULL DEFAULT '' COMMENT '迁移源文件sha256',
                  `from_version` varchar(50) NOT NULL DEFAULT '' COMMENT '升级前版本',
                  `to_version` varchar(50) NOT NULL DEFAULT '' COMMENT '升级后版本',
                  `batch` int NOT NULL DEFAULT '0' COMMENT '迁移批次',
                  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：0标记，1成功，-1失败',
                  `error_message` varchar(500) NOT NULL DEFAULT '' COMMENT '异常信息',
                  `executed_at` datetime DEFAULT NULL COMMENT '执行时间',
                  `created_by` int DEFAULT '0' COMMENT '创建人',
                  `updated_by` int DEFAULT '0' COMMENT '更新人',
                  `create_time` int DEFAULT '0' COMMENT '创建时间',
                  `update_time` int DEFAULT '0' COMMENT '更新时间',
                  `delete_time` int DEFAULT NULL COMMENT '删除时间',
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
}