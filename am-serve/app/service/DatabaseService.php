<?php

namespace app\service;

use app\model\plugin\App as Plugin;
use app\model\plugin\Widget;
use app\model\system\Config;
use app\model\system\Migration;
use app\model\system\Menu;
use app\model\system\RoleMenu;
use app\exception\ApiException;
use support\think\Db;

class DatabaseService
{
    public const INSTALL_SQL_FILE = 'install.sql';
    public const INSTALL_SQL_BEGIN = '-- ATRMAN generated plugin data migrations begin';
    public const INSTALL_SQL_END = '-- ATRMAN generated plugin data migrations end';
    public const SQL_PREFIX_PLACEHOLDER = '__PREFIX__';

    public static function pluginConfigRows(string $name): array
    {
        $roots = Config::where('app_name', $name)->where('pid', 0)->order('sort asc')->order('id asc')->select()->toArray();
        return self::collectTreeRows(Config::class, $roots);
    }

    public static function pluginMenuRows(string $name): array
    {
        $roots = Menu::where('app_name', $name)->order('sort asc')->order('id asc')->select()->toArray();
        return self::collectTreeRows(Menu::class, $roots);
    }

    public static function collectTreeRows(string $modelClass, array $roots): array
    {
        $rows = [];
        foreach ($roots as $root) {
            $rows[] = $root;
            $children = $modelClass::where('pid', $root['id'])->order('sort asc')->order('id asc')->select()->toArray();
            if ($children) {
                array_push($rows, ...self::collectTreeRows($modelClass, $children));
            }
        }
        return $rows;
    }

    public static function pluginWidgetRows(string $name): array
    {
        return Widget::where('app_name', $name)->order('id asc')->select()->toArray();
    }

    public static function pluginTableSnapshotsByNames(array $tableNames): array
    {
        $tables = [];
        foreach (array_values(array_unique(array_filter($tableNames))) as $table) {
            if (!self::tableExists($table)) {
                continue;
            }
            $createRows = Db::query('SHOW CREATE TABLE ' . self::quoteIdentifier($table));
            $createSql = (string)($createRows[0]['Create Table'] ?? '');
            $rows = Db::query('SELECT * FROM ' . self::quoteIdentifier($table));
            $tables[] = [
                'table' => $table,
                'create_sql' => $createSql,
                'rows' => $rows,
            ];
        }
        return $tables;
    }

    public static function tableExists(string $table): bool
    {
        $rows = Db::query('SHOW TABLES LIKE ' . self::sqlValue($table));
        return !empty($rows);
    }

    public static function pluginTableNames(string $name): array
    {
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $name) . '\\_%';
        $rows = Db::query('SHOW TABLES LIKE ' . self::sqlValue($pattern));
        $tables = [];
        foreach ($rows as $row) {
            $table = (string)array_values($row)[0];
            if ($table !== '') {
                $tables[$table] = $table;
            }
        }
        // 兼容业务表恰好与插件同名的情况（如 am_member 插件的 am_member 主表），LIKE 'name\_%' 匹配不到精确同名表
        if (self::tableExists($name)) {
            $tables[$name] = $name;
        }
        $tables = array_values($tables);
        sort($tables);
        return $tables;
    }

    public static function rowsToReplaceSql(string $table, array $rows): string
    {
        if (!$rows) {
            return '-- No rows for ' . $table . '.';
        }
        $lines = ['-- ' . $table];
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $lines[] = 'REPLACE INTO ' . self::quoteIdentifier($table)
                . ' (' . implode(', ', array_map(fn($column) => self::quoteIdentifier($column), $columns)) . ') VALUES ('
                . implode(', ', array_map(fn($column) => self::sqlValue($row[$column]), $columns)) . ');';
        }
        return implode("\n", $lines);
    }

    public static function pluginTablesToSql(array $tables, bool $ifNotExists = false): string
    {
        if (!$tables) {
            return '-- No plugin tables found.';
        }
        $sections = [];
        foreach ($tables as $table) {
            $lines = ['-- ' . $table['table']];
            if ($table['create_sql']) {
                $createSql = $ifNotExists
                    ? preg_replace('/^CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $table['create_sql'], 1)
                    : $table['create_sql'];
                $lines[] = $createSql . ';';
            }
            if ($table['rows']) {
                $lines[] = self::rowsToReplaceSql($table['table'], $table['rows']);
            }
            $sections[] = implode("\n", $lines);
        }
        return implode("\n\n", $sections);
    }

    public static function deletePluginRestoreData(string $name): void
    {
        $configIds = array_column(self::pluginConfigRows($name), 'id');
        $menuIds = array_column(self::pluginMenuRows($name), 'id');

        if ($menuIds) {
            self::deleteByIds((new RoleMenu())->getTable(), 'menu_id', $menuIds);
            self::deleteByIds((new Menu())->getTable(), 'id', $menuIds);
        }
        if ($configIds) {
            self::deleteByIds((new Config())->getTable(), 'id', $configIds);
        }
        self::deleteByField((new Widget())->getTable(), 'app_name', $name);
        self::deleteByField((new Migration())->getTable(), 'app_name', $name);
        foreach (self::pluginTableNames($name) as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . self::quoteIdentifier($table));
        }
    }

    public static function deletePluginDatabaseInfo(string $name, int $pluginId): void
    {
        self::deletePluginRestoreData($name);
        Plugin::destroy($pluginId, true);
    }

    public static function deleteByIds(string $table, string $field, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (!$ids) {
            return;
        }
        Db::execute('DELETE FROM ' . self::quoteIdentifier($table) . ' WHERE ' . self::quoteIdentifier($field) . ' IN (' . implode(',', $ids) . ')');
    }

    public static function deleteByField(string $table, string $field, string $value): void
    {
        Db::execute('DELETE FROM ' . self::quoteIdentifier($table) . ' WHERE ' . self::quoteIdentifier($field) . ' = ' . self::sqlValue($value));
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * 清理 install.sql 中历史遗留的基座生成数据块，返回文件路径。
     *
     * install.sql 的边界是「插件自有业务表 + 这些表的种子数据」：配置组、页面菜单、按钮权限、
     * 工作台小部件一律由插件在 afterInstall/afterEnable 调 PluginRegistrar 声明式注册，
     * 「管理入口」顶级菜单由 PluginManager::syncEntry 独占写入。旧版本打包时会把这些行写成
     * 幂等块塞进 install.sql，装到新站点就是「SQL 导一份 + 程序再声明一份」两份数据，
     * 故打包前剥离残留块，恢复单一写入者。
     *
     * 卸载备份的 restore.sql 仍需还原现场，pluginConfigRowsToMigrationSql 等导出函数保留给
     * BackupService 使用，此处不再调用。
     */
    public static function cleanInstallSql(string $sourceDir): string
    {
        $path = $sourceDir . '/' . self::INSTALL_SQL_FILE;
        if (!is_file($path)) {
            return $path;
        }
        $current = file_get_contents($path) ?: '';
        if (!str_contains($current, self::INSTALL_SQL_BEGIN) || !str_contains($current, self::INSTALL_SQL_END)) {
            return $path;
        }
        $pattern = '/' . preg_quote(self::INSTALL_SQL_BEGIN, '/') . '.*?' . preg_quote(self::INSTALL_SQL_END, '/') . '\n?/s';
        FileService::writePluginFile($path, ltrim((string)preg_replace($pattern, '', $current)));
        return $path;
    }

    protected static function renderNaturalKeyUpsertSql(string $table, string $var, string $lookup, array $insert, array $update, bool $withDeleteTime = true): string
    {
        $insertColumns = implode(', ', array_map(fn($column) => '`' . $column . '`', array_keys($insert)));
        $insertValues = implode(', ', array_values($insert));
        $assignments = [];
        foreach ($update as $column => $value) {
            $assignments[] = '`' . $column . '` = ' . $value;
        }
        $comparisons = [];
        foreach ($update as $column => $value) {
            if (in_array($column, ['updated_by', 'update_time'], true)) {
                continue;
            }
            $comparisons[] = '`' . $column . '` <=> ' . $value;
        }
        $whereChanged = $comparisons ? ' AND NOT (' . implode(' AND ', $comparisons) . ')' : '';
        $deleteWhere = $withDeleteTime ? ' AND `delete_time` IS NULL' : '';

        return "SET {$var} := (SELECT `id` FROM {$table} WHERE {$lookup}{$deleteWhere} ORDER BY `id` ASC LIMIT 1);\n"
            . "INSERT INTO {$table} ({$insertColumns}) SELECT {$insertValues} WHERE {$var} IS NULL;\n"
            . "SET {$var} := COALESCE({$var}, LAST_INSERT_ID());\n"
            . "UPDATE {$table} SET " . implode(', ', $assignments) . " WHERE {$var} IS NOT NULL AND `id` = {$var}{$whereChanged};";
    }

    protected static function prefixedTable(string $table): string
    {
        return '`' . self::SQL_PREFIX_PLACEHOLDER . $table . '`';
    }

    public static function pluginAppToRestoreSql(array $pluginSnapshot, array $manifest): string
    {
        $name = (string)($manifest['name'] ?? $pluginSnapshot['name'] ?? '');
        $snapshotManifest = \normalizeArray($pluginSnapshot['manifest'] ?? []);
        $manifest = PluginManifestService::completeManifest(array_merge($snapshotManifest, $manifest, ['name' => $name]));
        $data = array_merge($pluginSnapshot, $manifest);
        $data['name'] = $name;
        $data['path'] = 'plugin/' . $name;
        $data['status'] = 0;
        $data['manifest'] = $manifest;
        $data['error_message'] = '';
        $data['installed_at'] = $pluginSnapshot['installed_at'] ?? date('Y-m-d H:i:s');
        $data['enabled_at'] = null;

        $insert = [
            'name' => self::sqlValue($data['name']),
            'title' => self::sqlValue($data['title'] ?? $name),
            'icon' => self::sqlValue($data['icon'] ?? ''),
            'type' => (string)(int)($data['type'] ?? 1),
            'version' => self::sqlValue($data['version'] ?? '1.0.0'),
            'description' => self::sqlValue($data['description'] ?? ''),
            'author' => self::sqlValue($data['author'] ?? ''),
            'path' => self::sqlValue($data['path']),
            // 入口声明存于下方 manifest JSON（plugin_app 表无 entry/manage_entry 独立列），不单独写列
            'open_mode' => self::sqlValue($data['open_mode'] ?? 'micro'),
            'is_custom' => (string)(int)($data['is_custom'] ?? 0),
            'is_core' => (string)(int)($data['is_core'] ?? 0),
            'source' => (string)(int)($data['source'] ?? 1),
            'scope' => (string)(int)($data['scope'] ?? 1),
            'platform' => (string)(int)($data['platform'] ?? 1),
            'is_dev' => (string)(int)($data['is_dev'] ?? 0),
            'auth' => self::sqlValue($pluginSnapshot['auth'] ?? null),
            'status' => '0',
            'config' => self::sqlValue($pluginSnapshot['config'] ?? null),
            'manifest' => self::sqlValue($manifest),
            'error_message' => self::sqlValue(''),
            'installed_at' => self::sqlValue($data['installed_at']),
            'enabled_at' => 'NULL',
            'created_by' => '0',
            'updated_by' => '0',
            'create_time' => 'UNIX_TIMESTAMP()',
            'update_time' => 'UNIX_TIMESTAMP()',
            'delete_time' => 'NULL',
        ];
        $update = $insert;
        unset($update['created_by'], $update['create_time'], $update['delete_time']);

        return "-- plugin_app\n" . self::renderNaturalKeyUpsertSql(
            self::prefixedTable('plugin_app'),
            '@plugin_app',
            '`name` <=> ' . self::sqlValue($name),
            $insert,
            $update
        );
    }

    public static function pluginConfigRowsToMigrationSql(string $name, array $rows, bool $restoreValues = false): string
    {
        $lines = ['-- system_config'];
        if (!$rows) {
            $lines[] = '-- No plugin configs found.';
            return implode("\n", $lines);
        }

        $configVars = [];
        foreach ($rows as $index => $row) {
            $var = '@cfg_' . ($index + 1);
            $configVars[$row['id']] = $var;
            $pid = (int)($row['pid'] ?? 0);
            $targetPid = $pid === 0 || empty($configVars[$pid]) ? '0' : $configVars[$pid];
            $keyColumn = (string)($row['code'] ?? '') !== '' ? 'code' : 'name';
            $lookup = '`pid` <=> ' . $targetPid . ' AND `' . $keyColumn . '` <=> ' . self::sqlValue((string)($row[$keyColumn] ?? ''));
            if ($pid === 0) {
                $lookup .= ' AND `app_name` <=> ' . self::sqlValue($name);
            }
            $insert = [
                'pid' => $targetPid,
                'name' => self::sqlValue($row['name'] ?? ''),
                'code' => self::sqlValue($row['code'] ?? ''),
                'value' => self::sqlValue($row['value'] ?? null),
                'form_type' => self::sqlValue($row['form_type'] ?? null),
                'options' => self::sqlValue($row['options'] ?? null),
                'is_sys' => (string)(int)($row['is_sys'] ?? 0),
                'is_edit' => (string)(int)($row['is_edit'] ?? 1),
                'app_name' => self::sqlValue($pid === 0 ? $name : ($row['app_name'] ?? null)),
                'sort' => (string)(int)($row['sort'] ?? 0),
                'status' => (string)(int)($row['status'] ?? 1),
                'created_by' => '0',
                'updated_by' => '0',
                'create_time' => 'UNIX_TIMESTAMP()',
                'update_time' => 'UNIX_TIMESTAMP()',
                'delete_time' => 'NULL',
            ];
            $update = $insert;
            unset($update['created_by'], $update['create_time'], $update['delete_time']);
            if (!$restoreValues) {
                unset($update['value']);
            }
            $lines[] = self::renderNaturalKeyUpsertSql(self::prefixedTable('system_config'), $var, $lookup, $insert, $update);
        }
        return implode("\n", $lines);
    }

    public static function pluginMenuRowsToMigrationSql(string $name, array $rows): string
    {
        $lines = ['-- system_menu'];
        if (!$rows) {
            $lines[] = '-- No plugin menus found. 入口菜单由 PluginManager::syncEntry 维护（code = app:entry:' . self::sqlValue($name) . '）。';
            return implode("\n", $lines);
        }

        $menuVars = [];
        foreach ($rows as $index => $row) {
            $var = '@menu_' . ($index + 1);
            $menuVars[$row['id']] = $var;
            $pid = (int)($row['pid'] ?? 0);
            $targetPid = $pid === 0 || empty($menuVars[$pid]) ? '0' : $menuVars[$pid];
            if ((string)($row['code'] ?? '') !== '') {
                $lookup = '`pid` <=> ' . $targetPid . ' AND `code` <=> ' . self::sqlValue($row['code']);
            } elseif ((string)($row['slug'] ?? '') !== '') {
                $lookup = '`pid` <=> ' . $targetPid . ' AND `type` <=> ' . (int)($row['type'] ?? 0) . ' AND `slug` <=> ' . self::sqlValue($row['slug']);
            } elseif ((string)($row['path'] ?? '') !== '') {
                $lookup = '`pid` <=> ' . $targetPid . ' AND `type` <=> ' . (int)($row['type'] ?? 0) . ' AND `path` <=> ' . self::sqlValue($row['path']);
            } else {
                $lookup = '`pid` <=> ' . $targetPid . ' AND `type` <=> ' . (int)($row['type'] ?? 0) . ' AND `name` <=> ' . self::sqlValue($row['name'] ?? '');
            }
            $insert = [
                'pid' => $targetPid,
                'name' => self::sqlValue($row['name'] ?? ''),
                'code' => self::sqlValue($row['code'] ?? null),
                // 归属标识必须随菜单迁移：基座导航/插件子菜单过滤与卸载清理都依赖 app_name
                'app_name' => self::sqlValue($row['app_name'] ?? $name),
                'type' => (string)(int)($row['type'] ?? 1),
                'path' => self::sqlValue($row['path'] ?? ''),
                'method' => self::sqlValue($row['method'] ?? null),
                'component' => self::sqlValue($row['component'] ?? ''),
                'slug' => self::sqlValue($row['slug'] ?? null),
                'icon' => self::sqlValue($row['icon'] ?? ''),
                'sort' => (string)(int)($row['sort'] ?? 0),
                'is_hide' => (string)(int)($row['is_hide'] ?? 1),
                'is_cache' => (string)(int)($row['is_cache'] ?? 0),
                'is_fixed' => (string)(int)($row['is_fixed'] ?? 0),
                'is_full_page' => (string)(int)($row['is_full_page'] ?? 0),
                'is_iframe' => (string)(int)($row['is_iframe'] ?? 1),
                'status' => (string)(int)($row['status'] ?? 1),
                'created_by' => '0',
                'updated_by' => '0',
                'create_time' => 'UNIX_TIMESTAMP()',
                'update_time' => 'UNIX_TIMESTAMP()',
                'delete_time' => 'NULL',
            ];
            $update = $insert;
            unset($update['created_by'], $update['create_time'], $update['delete_time']);
            $lines[] = self::renderNaturalKeyUpsertSql(self::prefixedTable('system_menu'), $var, $lookup, $insert, $update);
        }
        return implode("\n", $lines);
    }

    public static function pluginWidgetRowsToMigrationSql(string $name, array $rows): string
    {
        $lines = ['-- plugin_widget'];
        if (!$rows) {
            $lines[] = '-- No plugin widgets found.';
            return implode("\n", $lines);
        }

        foreach ($rows as $index => $row) {
            $var = '@widget_' . ($index + 1);
            $lookup = '`app_name` <=> ' . self::sqlValue($name) . ' AND `name` <=> ' . self::sqlValue($row['name'] ?? '');
            $insert = [
                'app_name' => self::sqlValue($name),
                'name' => self::sqlValue($row['name'] ?? ''),
                'title' => self::sqlValue($row['title'] ?? ''),
                'description' => self::sqlValue($row['description'] ?? ''),
                'cate' => (string)(int)($row['cate'] ?? 1),
                'type' => self::sqlValue($row['type'] ?? ''),
                'url' => self::sqlValue($row['url'] ?? ''),
                'size' => self::sqlValue($row['size'] ?? ''),
                'status' => (string)(int)($row['status'] ?? 1),
            ];
            $lines[] = self::renderNaturalKeyUpsertSql(self::prefixedTable('plugin_widget'), $var, $lookup, $insert, $insert, false);
        }
        return implode("\n", $lines);
    }

    public static function executeInstallSql(Plugin $plugin): void
    {
        $root = FileService::pluginRootFromPath((string)$plugin->path, (string)$plugin->name);
        $path = $root . '/' . self::INSTALL_SQL_FILE;
        // 安装期占位符替换：使插件 install.sql 能引用自身记录 id、标识与入口菜单地址。
        // 入口菜单 path 使用 {{APP_ENTRY_PATH}}（不含 id），迁移到其他实例时 id 会变而标识不变。
        self::executeSqlFile($path, [
            '{{APP_ID}}' => (string)(int)$plugin->id,
            '{{APP_NAME}}' => (string)$plugin->name,
            '{{APP_ENTRY_PATH}}' => PluginManager::entryMenuPath((string)$plugin->name),
        ]);
    }

    public static function executeSqlFile(string $path, array $replacements = []): void
    {
        if (!is_file($path)) {
            return;
        }
        $sql = trim(file_get_contents($path) ?: '');
        if ($sql === '') {
            return;
        }

        $prefix = (string)(config('think-orm.connections.mysql.prefix') ?: env('DB_PREFIX', 'am_'));
        $sql = str_replace([self::SQL_PREFIX_PLACEHOLDER, '{{DB_PREFIX}}'], $prefix, $sql);
        if ($replacements) {
            $sql = strtr($sql, $replacements);
        }
        foreach (self::splitSqlStatements($sql) as $statement) {
            self::assertSafeStatement($statement);
            Db::execute($statement);
        }
    }

    /**
     * 插件 SQL 语句黑名单：插件迁移只准动自己的表。
     *
     * install.sql / 升级脚本是基座以超管权限直连数据库执行的原始 SQL，一旦放行到
     * 建库授权一类语句，一个恶意插件包就能建库、改账号甚至读走整个实例的数据。
     * splitSqlStatements 已剔除注释，这里看到的语句体不含注释文本。
     */
    protected const FORBIDDEN_SQL_PATTERNS = [
        '/\b(drop|create|alter)\s+(database|schema)\b/i'  => '库级操作',
        '/\b(drop|rename)\s+(user|database)\b/i'          => '账号/库级操作',
        '/\bcreate\s+user\b/i'                            => '账号创建',
        '/\balter\s+user\b/i'                             => '账号修改',
        '/\b(grant|revoke)\s+[^\n]*\bon\b/i'              => '授权变更',
        '/\binto\s+(outfile|dumpfile)\b/i'                => '服务端写文件',
        '/\bload_file\s*\(/i'                             => '服务端读文件',
        '/\bset\s+global\b/i'                             => '全局变量修改',
        '/\bshutdown\b/i'                                 => '关闭数据库',
    ];

    /**
     * @throws ApiException
     */
    public static function assertSafeStatement(string $statement): void
    {
        foreach (self::FORBIDDEN_SQL_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $statement)) {
                throw new ApiException('插件 SQL 含禁止的语句（' . $label . '），安装中止');
            }
        }
    }

    public static function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value) || is_object($value)) {
            return "'" . addslashes(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'";
        }
        return "'" . addslashes((string)$value) . "'";
    }

    public static function splitSqlStatements(string $sql): array
    {
        $len = strlen($sql);
        $statements = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $current .= $ch;
                }
                continue;
            }
            if ($inSingle) {
                if ($ch === '\\') {
                    $current .= $ch . $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = false;
                }
                $current .= $ch;
                continue;
            }
            if ($inDouble) {
                if ($ch === '\\') {
                    $current .= $ch . $next;
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = false;
                }
                $current .= $ch;
                continue;
            }
            if ($inBacktick) {
                if ($ch === '`') {
                    $inBacktick = false;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $current .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $current .= $ch;
                continue;
            }
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}
