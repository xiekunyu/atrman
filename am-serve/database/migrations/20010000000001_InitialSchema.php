<?php

use Phinx\Migration\AbstractMigration;

/**
 * 基座初始 schema：执行 install/install.sql（只实现 up()，建库不可逆）。
 * 前缀替换只锚定反引号内的表名，避免误伤数据中的 am_ 字面量。
 */
class InitialSchema extends AbstractMigration
{
    public function up(): void
    {
        $prefix = (string)$this->getAdapter()->getOption('table_prefix');
        $sql = (string)file_get_contents(__DIR__ . '/../../install/install.sql');
        $sql = preg_replace('/`am_/', '`' . $prefix, $sql);
        foreach (\app\service\system\DatabaseService::splitSqlStatements($sql) as $stmt) {
            $this->execute($stmt);
        }
    }
}