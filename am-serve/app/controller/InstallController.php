<?php

namespace app\controller;

use app\service\InstallService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/**
 * 系统安装控制器
 *
 * 不继承 BaseController，因为 BaseController 构造时调用 getCurrentInfo() 依赖 JWT，
 * 安装阶段 JWT 尚未配置，会导致报错。
 */
#[RouteGroup('/api/install')]
class InstallController
{
    /**
     * 获取安装状态
     */
    #[Get('/status')]
    public function status(Request $request): Response
    {
        $installed = InstallService::isInstalled();
        return success('', ['installed' => $installed]);
    }

    /**
     * 获取开源许可（MIT）内容
     */
    #[Get('/license')]
    public function license(Request $request): Response
    {
        $licensePath = base_path() . '/install/license.txt';
        if (!is_file($licensePath)) {
            return warning('开源许可文本不存在');
        }
        $content = file_get_contents($licensePath);
        return success('', ['content' => $content]);
    }

    /**
     * 确认许可条款
     */
    #[Post('/agree')]
    public function agree(Request $request): Response
    {
        $agreed = $request->post('agreed', false);
        if (!$agreed) {
            return warning('请阅读并同意开源许可条款');
        }
        return success('许可条款已确认');
    }

    /**
     * 环境检测
     */
    #[Get('/environment')]
    public function environment(Request $request): Response
    {
        $result = InstallService::checkEnvironment();
        return success('', $result);
    }

    /**
     * 测试数据库连接
     */
    #[Post('/testDatabase')]
    public function testDatabase(Request $request): Response
    {
        $config = $request->post();
        $required = ['db_host', 'db_port', 'db_name', 'db_user'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return warning("请填写 {$field}");
            }
        }

        $result = InstallService::testDatabaseConnection([
            'host' => $config['db_host'],
            'port' => $config['db_port'] ?? '3306',
            'database' => $config['db_name'],
            'username' => $config['db_user'],
            'password' => $config['db_password'] ?? '',
            'prefix' => $config['db_prefix'] ?? 'am_',
        ]);

        return $result['success']
            ? success('数据库连接成功', $result['data'] ?? [])
            : warning($result['message']);
    }

    /**
     * 测试 Redis 连接
     */
    #[Post('/testRedis')]
    public function testRedis(Request $request): Response
    {
        $config = $request->post();
        if (empty($config['redis_host'])) {
            return warning('请填写 Redis 地址');
        }

        $result = InstallService::testRedisConnection([
            'host' => $config['redis_host'],
            'port' => $config['redis_port'] ?? '6379',
            'password' => $config['redis_password'] ?? '',
        ]);

        return $result['success']
            ? success('Redis 连接成功', $result['data'] ?? [])
            : warning($result['message']);
    }

    /**
     * 检测数据库已有表（防覆盖）
     */
    #[Post('/checkDatabase')]
    public function checkDatabase(Request $request): Response
    {
        $config = $request->post();
        $result = InstallService::checkDatabaseExists([
            'host' => $config['db_host'] ?? '',
            'port' => $config['db_port'] ?? '3306',
            'database' => $config['db_name'] ?? '',
            'username' => $config['db_user'] ?? '',
            'password' => $config['db_password'] ?? '',
            'prefix' => $config['db_prefix'] ?? 'am_',
        ]);

        return success('', $result);
    }

    /**
     * 执行安装
     */
    #[Post('/execute')]
    public function execute(Request $request): Response
    {
        // 防止超时（SQL 导入可能耗时较长）
        set_time_limit(0);

        $config = $request->post();

        // 参数验证
        $errors = $this->validateInstallParams($config);
        if (!empty($errors)) {
            return warning($errors[0]);
        }

        $installConfig = [
            'app_url' => $config['app_url'] ?? '',
            'app_debug' => (bool)($config['app_debug'] ?? false),
            'app_timezone' => $config['app_timezone'] ?? 'Asia/Shanghai',
            'db_host' => $config['db_host'],
            'db_port' => $config['db_port'] ?? '3306',
            'db_name' => $config['db_name'],
            'db_user' => $config['db_user'],
            'db_password' => $config['db_password'] ?? '',
            'db_prefix' => $config['db_prefix'] ?? 'am_',
            'db_charset' => 'utf8mb4',
            'redis_host' => $config['redis_host'],
            'redis_port' => $config['redis_port'] ?? '6379',
            'redis_password' => $config['redis_password'] ?? '',
            'admin_username' => $config['admin_username'],
            'admin_password' => $config['admin_password'],
            'entry_file' => trim((string)($config['entry_file'] ?? '')),
        ];

        $result = InstallService::executeInstall($installConfig);

        return $result['success']
            ? success($result['message'] ?? '安装成功', $result['data'] ?? [])
            : warning($result['message']);
    }

    /**
     * 验证安装参数
     */
    private function validateInstallParams(array $config): array
    {
        $errors = [];

        if (empty($config['db_host'])) {
            $errors[] = '请填写数据库地址';
        }
        if (empty($config['db_name'])) {
            $errors[] = '请填写数据库名';
        }
        if (empty($config['db_user'])) {
            $errors[] = '请填写数据库用户名';
        }
        if (empty($config['redis_host'])) {
            $errors[] = '请填写 Redis 地址';
        }
        if (empty($config['admin_username'])) {
            $errors[] = '请填写管理员用户名';
        }
        if (empty($config['admin_password'])) {
            $errors[] = '请填写管理员密码';
        } elseif (strlen($config['admin_password']) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if (($config['admin_password'] ?? '') !== ($config['admin_password_confirm'] ?? '')) {
            $errors[] = '两次输入的密码不一致';
        }

        // 入口文件名：仅当填写时校验（空 = 随机生成，index.html = 默认）
        $entryFile = trim((string)($config['entry_file'] ?? ''));
        if ($entryFile !== '' && $entryFile !== 'index.html'
            && !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}\.html$/', $entryFile)) {
            $errors[] = '入口文件名格式不正确（字母开头，仅支持字母、数字、_、-，以 .html 结尾）';
        }

        return $errors;
    }
}
