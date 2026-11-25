<?php

namespace app\command;

use app\service\system\PhinxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 旧库基线标记：php webman phinx:mark base|插件标识
 * 幂等：补 phinx_version 列 + 回填存量行 + 已安装基座/插件自动 MARKED。
 * 正常升级无需手动执行（PhinxService::migrate 每次自动 ensureBaseline），本命令供核对/补救。
 */
class PhinxMark extends Command
{
    protected static $defaultName = 'phinx:mark';
    protected static $defaultDescription = '旧库基线标记（幂等，正常升级自动执行）';

    protected function configure(): void
    {
        $this->addArgument('app', InputArgument::REQUIRED, 'base=基座，或插件标识');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = PhinxService::mark((string)$input->getArgument('app'));
        $output->writeln('<info>基线标记完成：' . $result['app'] . '，本次标记/回填 ' . $result['marked'] . ' 行</info>');
        return Command::SUCCESS;
    }
}