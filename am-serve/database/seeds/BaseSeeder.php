<?php

use Phinx\Seed\AbstractSeed;

/** 基座初始数据：执行 install/seed.sql（状态机拆分，子进程安全）。 */
class BaseSeeder extends AbstractSeed
{
    public function run(): void
    {
        $prefix = (string)$this->getAdapter()->getOption('table_prefix');
        $sql = (string)file_get_contents(__DIR__ . '/../../install/seed.sql');
        $sql = preg_replace('/`am_/', '`' . $prefix, $sql);
        foreach (\app\service\system\DatabaseService::splitSqlStatements($sql) as $stmt) {
            $this->execute($stmt);
        }
    }
}