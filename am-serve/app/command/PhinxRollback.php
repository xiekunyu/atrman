<?php

namespace app\command;

use app\service\system\PhinxService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 回滚迁移：php webman phinx:rollback base|插件标识 --target=版本 [--confirm]
 * 注意：-t 语义是目标版本号（0=全部），不是步数；legacy 包装迁移无 down()，Phinx 会拒绝回滚。
 */
class PhinxRollback extends Command
{
    protected static $defaultName = 'phinx:rollback';
    protected static $defaultDescription = '回滚数据库迁移到目标版本';

    protected function configure(): void
    {
        $this->addArgument('app', InputArgument::REQUIRED, 'base=基座，或插件标识');
        $this->addOption('target', 't', InputOption::VALUE_REQUIRED, '目标版本（0=回滚全部）', '0');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, '回滚全部（target=0）时必须显式确认');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = (string)$input->getArgument('app');
        $target = (string)$input->getOption('target');
        if ($target === '0' && !$input->getOption('confirm')) {
            $output->writeln('<error>回滚全部迁移是破坏性操作，请追加 --confirm 显式确认</error>');
            return Command::FAILURE;
        }
        $result = PhinxService::rollback($app, $target, [], static function (string $line) use ($output): void {
            $output->writeln($line);
        });
        if ($result['exit'] !== 0) {
            $output->writeln('<error>回滚失败（退出码 ' . $result['exit'] . '）；legacy 包装迁移不可逆，请走备份/快照恢复</error>');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}