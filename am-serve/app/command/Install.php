<?php

namespace app\command;

use app\service\system\InstallService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 全新安装 CLI 入口：php webman install --db-host=... --db-name=... --db-user=... --db-pass=...
 *                     --admin-user=... --admin-pass=... --app-url=...
 * 与安装向导共用 InstallService::executeInstall（Phinx 执行初始 schema + 播种）。
 */
class Install extends Command
{
    protected static $defaultName = 'install';
    protected static $defaultDescription = '全新安装 Atrman（CLI 入口，与安装向导同一流程）';

    protected function configure(): void
    {
        $this->addOption('db-host', null, InputOption::VALUE_REQUIRED, '数据库主机', '127.0.0.1');
        $this->addOption('db-port', null, InputOption::VALUE_REQUIRED, '数据库端口', '3306');
        $this->addOption('db-name', null, InputOption::VALUE_REQUIRED, '数据库名');
        $this->addOption('db-user', null, InputOption::VALUE_REQUIRED, '数据库用户');
        $this->addOption('db-pass', null, InputOption::VALUE_REQUIRED, '数据库密码', '');
        $this->addOption('db-charset', null, InputOption::VALUE_REQUIRED, '字符集', 'utf8mb4');
        $this->addOption('redis-host', null, InputOption::VALUE_REQUIRED, 'Redis 主机', '127.0.0.1');
        $this->addOption('redis-port', null, InputOption::VALUE_REQUIRED, 'Redis 端口', '6379');
        $this->addOption('redis-pass', null, InputOption::VALUE_REQUIRED, 'Redis 密码', '');
        $this->addOption('admin-user', null, InputOption::VALUE_REQUIRED, '超级管理员用户名');
        $this->addOption('admin-pass', null, InputOption::VALUE_REQUIRED, '超级管理员密码');
        $this->addOption('app-url', null, InputOption::VALUE_REQUIRED, '站点 URL（如 https://example.com）');
        $this->addOption('entry-file', null, InputOption::VALUE_REQUIRED, '后台入口文件名（空=随机生成）', '');
        $this->addOption('app-debug', null, InputOption::VALUE_REQUIRED, '调试模式 true/false', 'false');
        $this->addOption('timezone', null, InputOption::VALUE_REQUIRED, '时区', 'Asia/Shanghai');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach (['db-name', 'db-user', 'admin-user', 'admin-pass', 'app-url'] as $required) {
            if (!$input->getOption($required)) {
                $output->writeln('<error>缺少必填选项 --' . $required . '</error>');
                return Command::FAILURE;
            }
        }
        $config = [
            'app_debug' => $input->getOption('app-debug'),
            'app_timezone' => $input->getOption('timezone'),
            'app_url' => $input->getOption('app-url'),
            'entry_file' => $input->getOption('entry-file'),
            'db_host' => $input->getOption('db-host'),
            'db_port' => $input->getOption('db-port'),
            'db_name' => $input->getOption('db-name'),
            'db_user' => $input->getOption('db-user'),
            'db_password' => $input->getOption('db-pass'),
            'db_charset' => $input->getOption('db-charset'),
            'redis_host' => $input->getOption('redis-host'),
            'redis_port' => $input->getOption('redis-port'),
            'redis_password' => $input->getOption('redis-pass'),
            'admin_username' => $input->getOption('admin-user'),
            'admin_password' => $input->getOption('admin-pass'),
        ];
        $result = InstallService::executeInstall($config);
        if (!empty($result['success'])) {
            $output->writeln('<info>' . $result['message'] . '</info>');
            if (!empty($result['data']['entry_url'])) {
                $output->writeln('后台入口：' . $result['data']['entry_url']);
            }
            return Command::SUCCESS;
        }
        $output->writeln('<error>' . ($result['message'] ?? '安装失败') . '</error>');
        return Command::FAILURE;
    }
}