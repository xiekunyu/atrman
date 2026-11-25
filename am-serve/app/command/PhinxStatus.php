<?php

namespace app\command;

use app\service\system\PhinxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 迁移状态：php webman phinx:status base|插件标识
 */
class PhinxStatus extends Command
{
    protected static $defaultName = 'phinx:status';
    protected static $defaultDescription = '查看数据库迁移状态';

    protected function configure(): void
    {
        $this->addArgument('app', InputArgument::REQUIRED, 'base=基座，或插件标识');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = PhinxService::status((string)$input->getArgument('app'));
        $output->writeln($result['log']);
        return $result['exit'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}