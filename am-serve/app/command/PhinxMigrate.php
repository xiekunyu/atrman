<?php

namespace app\command;

use app\service\system\PhinxService;
use app\model\system\Migration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 执行迁移：php webman phinx:migrate base|插件标识 [--target=版本] [--fake] [--dry-run]
 */
class PhinxMigrate extends Command
{
    protected static $defaultName = 'phinx:migrate';
    protected static $defaultDescription = '执行数据库迁移（Phinx 单台账）';

    protected function configure(): void
    {
        $this->addArgument('app', InputArgument::REQUIRED, 'base=基座，或插件标识（如 am_member）');
        $this->addOption('target', 't', InputOption::VALUE_REQUIRED, '目标版本（14 位时间戳，非步数）', '');
        $this->addOption('fake', null, InputOption::VALUE_NONE, '只标记为已执行，不真正运行 SQL');
        $this->addOption('dry-run', 'x', InputOption::VALUE_NONE, '预演：输出 SQL 不执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = (string)$input->getArgument('app');
        $paths = PhinxService::appPaths($app);
        if (!$paths) {
            $output->writeln('<comment>应用 ' . $app . ' 没有 PHP 迁移目录（database/migrations），无需执行</comment>');
            return Command::SUCCESS;
        }
        $context = [
            'migration_type' => $app === 'base' ? Migration::TYPE_SYSTEM : Migration::TYPE_PLUGIN,
            'from_version' => '',
            'to_version' => '',
            'dry_run' => (bool)$input->getOption('dry-run'),
        ];
        $result = PhinxService::migrate(
            $app,
            $paths,
            $context,
            [],
            (string)$input->getOption('target'),
            (bool)$input->getOption('fake'),
            static function (string $line) use ($output): void {
                $output->writeln($line);
            }
        );
        if ($result['exit'] !== 0) {
            $output->writeln('<error>迁移失败（退出码 ' . $result['exit'] . '）</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>完成，本次执行 ' . count($result['applied']) . ' 个迁移</info>');
        return Command::SUCCESS;
    }
}