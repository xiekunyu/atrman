<?php

/**
 * Phinx 子进程入口（PhinxService::exec 调用，勿手动执行）。
 * 用法：php phinx-runner.php <autoload路径> <配置文件路径> <phinx命令及参数...>
 * 自定义适配器须经 registerAdapter 注册（vendor/bin/phinx 无此钩子）；连接参数经 PHINX_DB_* 环境变量注入。
 */

if ($argc < 4) {
    fwrite(STDERR, "usage: php phinx-runner.php <autoload> <config> <command> [args...]\n");
    exit(1);
}

$autoload = $argv[1];
$configFile = $argv[2];
if (!is_file($autoload) || !is_file($configFile)) {
    fwrite(STDERR, "autoload or config file not found\n");
    exit(1);
}

require $autoload;

// 注册单台账适配器（覆盖默认 mysql 适配器）
\Phinx\Db\Adapter\AdapterFactory::instance()->registerAdapter('mysql', \app\database\MigrationAdapter::class);

// 组装 Symfony Console 的 argv（phinx 命令 + -c 配置）
$_SERVER['argv'] = array_merge(['phinx'], array_slice($argv, 3), ['-c', $configFile]);
$_SERVER['argc'] = count($_SERVER['argv']);

$app = require dirname($autoload) . '/robmorgan/phinx/app/phinx.php';
$app->setAutoExit(false);
exit((int)$app->run());