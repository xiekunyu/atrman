<?php

namespace app\service\provider;

use app\exception\ApiException;
use app\service\CoreUpgradeService;
use app\service\FileService;
use app\service\PluginManifestService;
use app\service\ReleaseManifestService;
use app\utils\Directory;
use app\utils\Security;

/**
 * Mock 数据源提供者
 *
 * 在本地完整模拟应用市场行为，用于开发联调：
 * - 市场账号登录/续期/注销（测试账号：任意用户名 + 密码 123456）
 * - 免费/付费应用下载凭证签发（付费应用签发 License）
 * - 安装包按需生成（基于 stub 脚手架），完整性由开发密钥签发的发布清单承担
 * - License 心跳
 *
 * 开发密钥对自动生成于 runtime/mock/market_keys.json，
 * 验签公钥通过 signingPublicKey() 提供给 StoreService。
 */
class MockStoreProvider implements StoreProvider
{
    /** 测试账号密码（仅 Mock） */
    protected const TEST_PASSWORD = '123456';

    /** Mock 主程序最新版（systemUpdate 与发布清单共用同一现场，两处必须一致） */
    protected const MOCK_CORE_LATEST = '1.0.1';

    /** Mock 应用数据（is_paid=1 为付费应用，purchased 表示测试账号已购） */
    protected array $apps = [
        [
            'id' => 'app_crm', 'name' => 'am_crm', 'title' => 'CRM客户管理系统',
            'description' => '强大的客户关系管理系统，支持客户跟进、商机管理、合同管理等功能',
            'author' => 'Atrman', 'version' => '1.2.0', 'category' => 'business',
            'tags' => ['CRM', '客户管理', '销售'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 1250, 'is_recommend' => 1,
            'changelog' => "1. 新增客户跟进提醒功能\n2. 优化商机管理流程\n3. 修复合同审批bug",
        ],
        [
            'id' => 'app_hr', 'name' => 'am_hr', 'title' => '人力资源管理系统',
            'description' => '完整的人力资源管理解决方案，包含招聘、考勤、绩效、薪酬等模块',
            'author' => 'Atrman', 'version' => '2.0.0', 'category' => 'business',
            'tags' => ['HR', '人事', '考勤'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 890, 'is_recommend' => 1,
            'changelog' => "1. 新增移动端考勤打卡\n2. 优化薪酬计算逻辑",
        ],
        [
            'id' => 'app_finance', 'name' => 'am_finance', 'title' => '财务报销系统',
            'description' => '企业财务报销管理系统，支持费用申请、审批、报销全流程',
            'author' => 'Atrman', 'version' => '1.5.0', 'category' => 'business',
            'tags' => ['财务', '报销', '审批'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 650, 'is_recommend' => 0,
            'changelog' => "1. 新增发票OCR识别\n2. 支持多币种报销",
        ],
        [
            'id' => 'app_project', 'name' => 'am_project', 'title' => '项目管理系统',
            'description' => '敏捷项目管理系统，支持任务分配、进度跟踪、团队协作',
            'author' => 'Atrman', 'version' => '1.0.0', 'category' => 'tool',
            'tags' => ['项目管理', '任务', '协作'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 420, 'is_recommend' => 0,
            'changelog' => "1. 初始版本发布\n2. 支持看板视图",
        ],
        [
            'id' => 'app_doc', 'name' => 'am_doc', 'title' => '文档管理系统',
            'description' => '企业文档管理平台，支持文档存储、版本管理、在线预览、权限控制',
            'author' => 'Atrman', 'version' => '1.3.0', 'category' => 'tool',
            'tags' => ['文档', '知识库', '协作'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 780, 'is_recommend' => 1,
            'changelog' => "1. 新增在线编辑功能\n2. 支持文档版本对比",
        ],
        [
            'id' => 'app_workflow_pro', 'name' => 'am_workflow_pro', 'title' => '工作流引擎专业版',
            'description' => '可视化工作流设计器专业版，支持复杂审批流、业务流编排、流程监控（付费授权，绑定实例）',
            'author' => 'Atrman', 'version' => '2.1.0', 'category' => 'tool',
            'tags' => ['工作流', '审批', '专业版'], 'is_paid' => 1, 'price' => 999, 'price_unit' => '元',
            'license_type' => 'perpetual', 'purchased' => 1,
            'download_count' => 320, 'is_recommend' => 1,
            'changelog' => "1. 新增流程设计器\n2. 支持会签、或签",
        ],
        [
            'id' => 'app_report_pro', 'name' => 'am_report_pro', 'title' => '报表分析专业版',
            'description' => '数据可视化报表平台专业版，支持自定义报表、数据大屏（付费授权，测试账号未购买，用于验证购买拦截）',
            'author' => 'Atrman', 'version' => '1.8.0', 'category' => 'business',
            'tags' => ['报表', '数据分析', '专业版'], 'is_paid' => 1, 'price' => 1999, 'price_unit' => '元',
            'license_type' => 'perpetual', 'purchased' => 0,
            'download_count' => 150, 'is_recommend' => 0,
            'changelog' => "1. 新增数据大屏模板",
        ],
        [
            'id' => 'app_monitor_pro', 'name' => 'am_monitor_pro', 'title' => '系统监控专业版',
            'description' => '服务器与应用性能监控专业版，订阅制授权一年，支持告警通知（用于验证订阅到期与心跳）',
            'author' => 'Atrman', 'version' => '1.0.0', 'category' => 'tool',
            'tags' => ['监控', '运维', '订阅制'], 'is_paid' => 1, 'price' => 499, 'price_unit' => '元',
            'license_type' => 'subscription', 'license_period' => 31536000, 'purchased' => 1,
            'download_count' => 210, 'is_recommend' => 0,
            'changelog' => "1. 初始版本发布",
        ],
        [
            'id' => 'app_message', 'name' => 'am_message', 'title' => '消息中心',
            'description' => '统一消息管理平台，支持站内信、邮件、短信、企业微信等多渠道推送',
            'author' => 'Atrman', 'version' => '1.1.0', 'category' => 'integration',
            'tags' => ['消息', '通知', '推送'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 2100, 'is_recommend' => 1,
            'changelog' => "1. 新增企业微信推送",
        ],
        [
            'id' => 'app_storage', 'name' => 'am_storage', 'title' => '对象存储插件',
            'description' => '多云对象存储支持，兼容阿里云OSS、腾讯云COS、七牛云、MinIO等',
            'author' => 'Atrman', 'version' => '1.0.0', 'category' => 'integration',
            'tags' => ['存储', 'OSS', '云服务'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 1800, 'is_recommend' => 0,
            'changelog' => "1. 初始版本发布",
        ],
        [
            'id' => 'app_wechat', 'name' => 'am_wechat', 'title' => '微信公众号集成',
            'description' => '微信公众号管理插件，支持菜单管理、素材管理、粉丝管理、消息回复',
            'author' => 'Atrman', 'version' => '1.4.0', 'category' => 'integration',
            'tags' => ['微信', '公众号', '营销'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 1350, 'is_recommend' => 1,
            'changelog' => "1. 新增自动回复功能",
        ],
        [
            'id' => 'app_dingtalk', 'name' => 'am_dingtalk', 'title' => '钉钉集成',
            'description' => '钉钉企业应用集成，支持组织架构同步、消息推送、审批流程对接',
            'author' => 'Atrman', 'version' => '1.2.0', 'category' => 'integration',
            'tags' => ['钉钉', 'OA', '协同'], 'is_paid' => 0, 'price' => 0, 'price_unit' => '元',
            'download_count' => 980, 'is_recommend' => 0,
            'changelog' => "1. 新增审批流程对接",
        ],
    ];

    protected array $categories = [
        ['key' => 'business', 'label' => '业务应用', 'icon' => 'ri:briefcase-line'],
        ['key' => 'tool', 'label' => '工具插件', 'icon' => 'ri:tools-line'],
        ['key' => 'integration', 'label' => '集成应用', 'icon' => 'ri:plug-line'],
    ];

    /** Mock 版本历史（每个应用 3~5 个版本，按发布时间倒序） */
    protected array $versionHistory = [
        'app_crm' => [
            ['version' => '1.2.0', 'release_date' => '2026-09-20', 'changelog' => "1. 新增客户跟进提醒功能\n2. 优化商机管理流程\n3. 修复合同审批bug"],
            ['version' => '1.1.0', 'release_date' => '2026-07-15', 'changelog' => "1. 新增客户导入功能\n2. 优化列表筛选性能"],
            ['version' => '1.0.1', 'release_date' => '2026-05-10', 'changelog' => "1. 修复客户详情页加载异常\n2. 优化移动端适配"],
            ['version' => '1.0.0', 'release_date' => '2026-03-01', 'changelog' => "1. 初始版本发布\n2. 支持客户管理、商机跟踪、合同审批"],
        ],
        'app_hr' => [
            ['version' => '2.0.0', 'release_date' => '2026-08-20', 'changelog' => "1. 新增移动端考勤打卡\n2. 优化薪酬计算逻辑"],
            ['version' => '1.5.0', 'release_date' => '2026-06-01', 'changelog' => "1. 新增绩效考核模块\n2. 支持自定义考勤规则"],
            ['version' => '1.0.0', 'release_date' => '2026-02-15', 'changelog' => "1. 初始版本发布\n2. 基础人事管理与考勤功能"],
        ],
        'app_finance' => [
            ['version' => '1.5.0', 'release_date' => '2026-09-01', 'changelog' => "1. 新增发票OCR识别\n2. 支持多币种报销"],
            ['version' => '1.2.0', 'release_date' => '2026-06-20', 'changelog' => "1. 优化审批流程\n2. 新增费用预算管理"],
            ['version' => '1.0.0', 'release_date' => '2026-03-10', 'changelog' => "1. 初始版本发布\n2. 基础费用申请与报销功能"],
        ],
        'app_project' => [
            ['version' => '1.0.0', 'release_date' => '2026-07-01', 'changelog' => "1. 初始版本发布\n2. 支持看板视图"],
        ],
        'app_doc' => [
            ['version' => '1.3.0', 'release_date' => '2026-09-15', 'changelog' => "1. 新增在线编辑功能\n2. 支持文档版本对比"],
            ['version' => '1.2.0', 'release_date' => '2026-07-20', 'changelog' => "1. 新增文档权限管理\n2. 优化文件预览速度"],
            ['version' => '1.1.0', 'release_date' => '2026-05-05', 'changelog' => "1. 支持文件夹管理\n2. 新增批量上传功能"],
            ['version' => '1.0.0', 'release_date' => '2026-03-15', 'changelog' => "1. 初始版本发布\n2. 基础文档存储与预览"],
        ],
        'app_workflow_pro' => [
            ['version' => '2.1.0', 'release_date' => '2026-09-25', 'changelog' => "1. 新增流程设计器\n2. 支持会签、或签"],
            ['version' => '2.0.0', 'release_date' => '2026-07-10', 'changelog' => "1. 架构升级\n2. 支持复杂业务流编排"],
            ['version' => '1.0.0', 'release_date' => '2026-04-01', 'changelog' => "1. 初始版本发布\n2. 基础审批流设计"],
        ],
        'app_report_pro' => [
            ['version' => '1.8.0', 'release_date' => '2026-09-10', 'changelog' => "1. 新增数据大屏模板"],
            ['version' => '1.5.0', 'release_date' => '2026-07-05', 'changelog' => "1. 新增自定义报表功能\n2. 支持数据导出"],
            ['version' => '1.0.0', 'release_date' => '2026-04-20', 'changelog' => "1. 初始版本发布\n2. 基础报表展示"],
        ],
        'app_monitor_pro' => [
            ['version' => '1.0.0', 'release_date' => '2026-08-01', 'changelog' => "1. 初始版本发布"],
        ],
        'app_message' => [
            ['version' => '1.1.0', 'release_date' => '2026-09-18', 'changelog' => "1. 新增企业微信推送"],
            ['version' => '1.0.1', 'release_date' => '2026-07-25', 'changelog' => "1. 修复邮件发送异常\n2. 优化消息队列处理"],
            ['version' => '1.0.0', 'release_date' => '2026-05-20', 'changelog' => "1. 初始版本发布\n2. 支持站内信、邮件、短信推送"],
        ],
        'app_storage' => [
            ['version' => '1.0.0', 'release_date' => '2026-06-15', 'changelog' => "1. 初始版本发布\n2. 支持阿里云OSS、腾讯云COS、七牛云、MinIO"],
        ],
        'app_wechat' => [
            ['version' => '1.4.0', 'release_date' => '2026-09-22', 'changelog' => "1. 新增自动回复功能"],
            ['version' => '1.3.0', 'release_date' => '2026-08-05', 'changelog' => "1. 新增粉丝管理\n2. 优化菜单配置"],
            ['version' => '1.2.0', 'release_date' => '2026-06-10', 'changelog' => "1. 新增素材管理功能"],
            ['version' => '1.0.0', 'release_date' => '2026-04-01', 'changelog' => "1. 初始版本发布\n2. 基础公众号管理"],
        ],
        'app_dingtalk' => [
            ['version' => '1.2.0', 'release_date' => '2026-09-05', 'changelog' => "1. 新增审批流程对接"],
            ['version' => '1.1.0', 'release_date' => '2026-07-15', 'changelog' => "1. 优化组织架构同步\n2. 新增消息推送"],
            ['version' => '1.0.0', 'release_date' => '2026-05-01', 'changelog' => "1. 初始版本发布\n2. 基础钉钉集成"],
        ],
    ];

    public function name(): string
    {
        return 'mock';
    }

    public function list(array $params = []): array
    {
        $list = $this->apps;

        if (!empty($params['category'])) {
            $list = array_filter($list, fn($app) => $app['category'] === $params['category']);
        }
        if (!empty($params['keyword'])) {
            $keyword = strtolower((string)$params['keyword']);
            $list = array_filter($list, fn($app) =>
                str_contains(strtolower($app['title']), $keyword)
                || str_contains(strtolower($app['description']), $keyword)
                || str_contains(strtolower($app['name']), $keyword));
        }
        if (!empty($params['recommend'])) {
            $list = array_filter($list, fn($app) => (int)$app['is_recommend'] === 1);
        }

        $orderField = (string)($params['orderField'] ?? 'download_count');
        $orderType = strtolower((string)($params['orderType'] ?? 'desc'));
        $list = array_values($list);
        usort($list, function ($a, $b) use ($orderField, $orderType) {
            $cmp = ($a[$orderField] ?? 0) <=> ($b[$orderField] ?? 0);
            return $orderType === 'asc' ? $cmp : -$cmp;
        });

        // 列表不返回任何下载地址，付费状态按测试账号购买记录标注
        return array_map(function ($app) {
            unset($app['changelog']);
            $app['purchased'] = (int)$app['is_paid'] === 1 ? (int)($app['purchased'] ?? 0) : 1;
            $app['license_required'] = (int)$app['is_paid'];
            return $app;
        }, $list);
    }

    public function detail(string $appId): ?array
    {
        foreach ($this->list() as $app) {
            if ($app['id'] === $appId || $app['name'] === $appId) {
                $raw = $this->findRaw($appId);
                // 补齐与真实市场 detailApp 同口径的最新版包体元信息（升级确认弹窗用）
                // 发布日期取版本历史首行（按发布时间倒序，首行即当前版本）
                return $raw ? array_merge($raw, [
                    'purchased' => $app['purchased'],
                    'license_required' => $app['license_required'],
                    'support_system_version' => (string)($raw['support_system_version'] ?? '1.0.0'),
                    'package_size' => (int)($raw['package_size'] ?? 262144),
                    'release_date' => (string)($raw['release_date'] ?? $this->versionHistory[$raw['id']][0]['release_date'] ?? ''),
                ]) : null;
            }
        }
        return null;
    }

    public function checkName(string $name): array
    {
        // 市场全局唯一：命中 Mock 应用库任一标识（含未上架）即占用
        foreach ($this->apps as $app) {
            if (($app['name'] ?? '') === $name || ($app['id'] ?? '') === $name) {
                return ['available' => false, 'reason' => 'market', 'message' => '应用标识已被应用市场占用，请更换其他标识'];
            }
        }
        return ['available' => true, 'reason' => '', 'message' => '标识可用'];
    }

    public function categories(): array
    {
        return $this->categories;
    }

    public function versions(string $appId): array
    {
        // 兼容 app_key（id）与 name 两种标识
        $key = null;
        foreach ($this->apps as $app) {
            if ($app['id'] === $appId || $app['name'] === $appId) {
                $key = $app['id'];
                break;
            }
        }
        return $key ? ($this->versionHistory[$key] ?? []) : [];
    }

    public function checkUpdate(array $installed): array
    {
        $updates = [];
        foreach ($installed as $plugin) {
            $remote = $this->findRaw((string)($plugin['name'] ?? ''));
            if ($remote && version_compare($remote['version'], (string)($plugin['version'] ?? ''), '>')) {
                $updates[] = [
                    'id' => $remote['id'],
                    'name' => $remote['name'],
                    'title' => $remote['title'],
                    'current_version' => $plugin['version'],
                    'new_version' => $remote['version'],
                    'is_paid' => (int)$remote['is_paid'],
                    'changelog' => $remote['changelog'] ?? '',
                ];
            }
        }
        return $updates;
    }

    public function systemUpdate(string $currentVersion): ?array
    {
        $latestVersion = self::MOCK_CORE_LATEST;
        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');
        $result = [
            'has_update' => $hasUpdate,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'changelog' => $hasUpdate ? "1. 新增应用商店与OAuth授权\n2. 优化插件安装流程" : '',
            'release_date' => '2026-10-10',
        ];

        // 与真实市场同结构的选包结果（Mock 为单步增量链：当前版本 → 最新版），
        // 包体只含无害演示文件，供基座升级链路联调。
        if ($hasUpdate) {
            $package = [
                'version' => $latestVersion,
                'package_type' => 1,
                'delta_from_version' => $currentVersion,
                'sha256' => '',
                'size' => 0,
                'is_required' => 0,
                'changelog' => 'Mock 主程序增量包（仅含演示文件 var/core-upgrade-mock.txt）',
                'support_system_version' => '',
                'composer_requires' => new \stdClass(),
                'upgrade_config' => (object)[
                    'need_restart' => false,
                    'migrations' => [],
                    'changed_files' => ['var/core-upgrade-mock.txt'],
                ],
            ];
            try {
                $zip = $this->ensureSystemMockPackage($latestVersion);
                $package['sha256'] = (string)Security::fileSha256($zip);
                $package['size'] = (int)filesize($zip);
            } catch (\Throwable $e) {
                // 基座升级要求每包必带有效 sha256（缺值即拒绝执行），Mock 也不例外：
                // 环境无法生成演示包（如缺 ZipArchive）时直接报错，不返回一个必然被拒的假更新
                throw new ApiException('Mock 主程序演示包生成失败：' . $e->getMessage());
            }
            $result['upgrade'] = ['mode' => 'delta', 'packages' => [$package]];
        }
        return $result;
    }

    public function requestSystemDownloadTicket(array $package, array $context): array
    {
        $this->assertToken((string)($context['market_token'] ?? ''));
        $version = (string)($package['version'] ?? '');
        if ($version === '') {
            throw new ApiException('缺少待装包版本号');
        }
        $zip = $this->ensureSystemMockPackage($version);
        return [
            'url' => $zip,
            'sha256' => (string)Security::fileSha256($zip),
            'version' => $version,
            'package_type' => (int)($package['package_type'] ?? 1),
            'expires_at' => time() + 300,
        ];
    }

    // ─────────────────────── 市场账号（Mock） ───────────────────────

    public function login(array $credentials): array
    {
        $username = trim((string)($credentials['username'] ?? ''));
        $password = (string)($credentials['password'] ?? '');
        if ($username === '' || $password !== self::TEST_PASSWORD) {
            throw new ApiException('市场账号或密码错误（Mock 测试密码：123456）');
        }

        $sessions = $this->readSessions();
        $token = 'mock_tk_' . Security::randomHex(16);
        $refreshToken = 'mock_rt_' . Security::randomHex(16);
        $sessions[$token] = [
            'username' => $username,
            'refresh_token' => $refreshToken,
            'expires_at' => time() + 7200,
        ];
        $this->writeSessions($sessions);

        return [
            'token' => $token,
            'refresh_token' => $refreshToken,
            'expires_at' => time() + 7200,
            'username' => $username,
            'nickname' => $username . '（Mock账号）',
        ];
    }

    public function refreshToken(string $refreshToken): ?array
    {
        $sessions = $this->readSessions();
        foreach ($sessions as $token => $session) {
            if (($session['refresh_token'] ?? '') === $refreshToken) {
                unset($sessions[$token]);
                $newToken = 'mock_tk_' . Security::randomHex(16);
                $newRefresh = 'mock_rt_' . Security::randomHex(16);
                $sessions[$newToken] = [
                    'username' => $session['username'],
                    'refresh_token' => $newRefresh,
                    'expires_at' => time() + 7200,
                ];
                $this->writeSessions($sessions);
                return [
                    'token' => $newToken,
                    'refresh_token' => $newRefresh,
                    'expires_at' => time() + 7200,
                    'username' => $session['username'],
                    'nickname' => $session['username'] . '（Mock账号）',
                ];
            }
        }
        return null;
    }

    public function logout(string $token): bool
    {
        $sessions = $this->readSessions();
        if (isset($sessions[$token])) {
            unset($sessions[$token]);
            $this->writeSessions($sessions);
        }
        return true;
    }

    // ─────────────────────── 下载凭证与心跳（Mock） ───────────────────────

    public function requestDownloadTicket(string $appId, array $context): array
    {
        $this->assertToken((string)($context['market_token'] ?? ''));
        $app = $this->findRaw($appId);
        if (!$app) {
            throw new ApiException('应用不存在：' . $appId);
        }

        // 支持指定版本：从 versionHistory 查找，找到则覆盖 app 的 version
        $requestedVersion = (string)($context['version'] ?? '');
        if ($requestedVersion !== '') {
            $history = $this->versionHistory[$app['id']] ?? [];
            $found = false;
            foreach ($history as $v) {
                if ($v['version'] === $requestedVersion) {
                    $app['version'] = $requestedVersion;
                    $app['changelog'] = $v['changelog'];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new ApiException('指定版本不存在：' . $requestedVersion);
            }
        }

        $isPaid = (int)$app['is_paid'] === 1;
        if ($isPaid && (int)($app['purchased'] ?? 0) !== 1) {
            // 未购买不抛异常，返回结构化结果由 StoreService 转换为购买引导
            return [
                'need_purchase' => true,
                'price' => $app['price'],
                'buy_url' => 'https://market.example.com/apps/' . $app['id'] . '/buy',
                'message' => '该应用为付费应用，当前市场账号未购买',
            ];
        }

        // 按需生成安装包（付费应用在下方另附 License）
        $packagePath = $this->ensurePackage($app);

        $ticket = [
            'url' => $packagePath,
            'sha256' => Security::fileSha256($packagePath),
            'license_required' => $isPaid,
            'expires_at' => time() + 300,
        ];

        if ($isPaid) {
            $ticket['license'] = $this->issueLicense($app, $context);
        }

        return $ticket;
    }

    public function heartbeat(array $context): array
    {
        try {
            $this->assertToken((string)($context['market_token'] ?? ''));
        } catch (\Throwable $e) {
            // 心跳时账号凭证失效不直接判定授权无效，交由宽限期机制处理
            return ['valid' => true, 'status' => 1, 'message' => '市场账号凭证待刷新，本次跳过在线校验'];
        }

        $plugin = (string)($context['plugin'] ?? '');
        $app = $this->findRaw($plugin);
        if (!$app) {
            return ['valid' => false, 'status' => -1, 'revoked' => true, 'message' => '市场已下架该应用'];
        }
        if ((int)$app['is_paid'] === 1 && (int)($app['purchased'] ?? 0) !== 1) {
            return ['valid' => false, 'status' => -1, 'revoked' => true, 'message' => '授权已被市场吊销（模拟退款/封禁）'];
        }

        return [
            'valid' => true,
            'status' => 1,
            'expires_at' => (int)($context['license_expires_at'] ?? 0),
            'message' => '授权有效',
        ];
    }

    public function signingPublicKey(): array
    {
        $keys = $this->ensureKeyPair();
        return ['alg' => $keys['alg'], 'public_key' => $keys['public']];
    }

    /**
     * Mock 发布清单：用开发密钥即时签发，条目取自 Mock 现场生成的包。
     *
     * 结构与真实市场逐字一致（8 个标量载荷 + entries），供基座 ReleaseManifestService
     * 在 provider=mock 下跑通同一条验签链路；revision 恒 1（Mock 无历史）。
     * 条目覆盖版本历史的全部版本（与真实市场 collectEntries 收「所有已发布版本」同口径）：
     * 只签最新版会让「安装指定旧版本」在开发态被基座判成「该版本不在官方发布清单内」。
     */
    public function releaseManifest(string $plugin, int $sinceRevision = 0): ?array
    {
        if ($plugin === CoreUpgradeService::CORE_NAME) {
            $entries = $this->mockCoreEntries();
        } else {
            $app = $this->findRaw($plugin);
            if ($app === null) {
                throw new ApiException('应用不存在：' . $plugin, 404);
            }
            $entries = $this->mockPluginEntries($app);
        }

        $keys = $this->ensureKeyPair();
        $issuedAt = time();
        $payload = [
            'schema' => ReleaseManifestService::SCHEMA,
            'issuer' => ReleaseManifestService::ISSUER,
            'plugin' => $plugin,
            'revision' => 1,
            'issued_at' => $issuedAt,
            // 7 天有效期：与真实市场 plugin/am_store ReleaseManifestService::VALID_SECONDS 同值
            'expires_at' => $issuedAt + 604800,
            'entries_hash' => Security::releaseEntriesHash($entries),
            'alg' => $keys['alg'],
        ];
        $payload['signature'] = Security::sign(Security::canonicalPayload($payload), $keys['private'], $keys['alg']);
        // entries 不进签名载荷（签的是 entries_hash），但要随应答下发
        return $payload + ['entries' => $entries];
    }

    /**
     * Mock 主程序条目：只有一条增量链「站点当前版本 → Mock 最新版」，
     * 与 systemUpdate 的现场口径一致（delta_from 取站点自身版本）。
     * 站点已是最新时无条目 = 无可装版本。
     */
    protected function mockCoreEntries(): array
    {
        $current = PluginManifestService::getAppVersion();
        if (version_compare(self::MOCK_CORE_LATEST, $current, '<=')) {
            return [];
        }
        $zipPath = $this->ensureSystemMockPackage(self::MOCK_CORE_LATEST);
        return [[
            'version' => self::MOCK_CORE_LATEST,
            'package_type' => 1,
            'delta_from' => $current,
            'sha256' => strtolower((string)Security::fileSha256($zipPath)),
            'size' => (int)filesize($zipPath),
            'channel' => 'stable',
            'is_required' => 0,
        ]];
    }

    // ─────────────────────── Mock 内部实现 ───────────────────────

    /**
     * Mock 插件条目：版本历史里的每个版本（含当前版本）各生成一个包并登记哈希。
     *
     * 包文件名按「名称_版本」落盘，与 requestDownloadTicket 生成的是同一个文件，
     * 所以「清单登记的哈希」与「站点下载到的字节」必然一致；首次调用会为历史版本各生成
     * 一次脚手架包，之后复用不重建。
     *
     * @return array<int,array{version:string,package_type:int,delta_from:string,sha256:string,size:int,channel:string,is_required:int}>
     */
    protected function mockPluginEntries(array $app): array
    {
        $versions = [];
        foreach ($this->versionHistory[$app['id']] ?? [] as $row) {
            $versions[(string)$row['version']] = true;
        }
        $versions[(string)$app['version']] = true;

        $entries = [];
        foreach (array_keys($versions) as $version) {
            $app['version'] = $version;
            $zipPath = $this->ensurePackage($app);
            $entries[] = [
                'version' => $version,
                'package_type' => 0,
                'delta_from' => '',
                'sha256' => strtolower((string)Security::fileSha256($zipPath)),
                'size' => (int)filesize($zipPath),
                'channel' => 'stable',
                'is_required' => 0,
            ];
        }
        return $entries;
    }

    /** 查找原始应用数据（含 changelog 等完整字段） */
    protected function findRaw(string $appId): ?array
    {
        foreach ($this->apps as $app) {
            if ($app['id'] === $appId || $app['name'] === $appId) {
                return $app;
            }
        }
        return null;
    }

    /** 校验 Mock 会话 token */
    protected function assertToken(string $token): void
    {
        $sessions = $this->readSessions();
        $session = $sessions[$token] ?? null;
        if (!$session) {
            throw new ApiException('市场账号凭证无效，请重新绑定');
        }
        if ((int)$session['expires_at'] < time()) {
            throw new ApiException('市场账号凭证已过期，请重新绑定');
        }
    }

    protected function mockRoot(): string
    {
        $root = runtime_path() . '/mock';
        Directory::ensureDir($root);
        return $root;
    }

    protected function readSessions(): array
    {
        $path = $this->mockRoot() . '/sessions.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    protected function writeSessions(array $sessions): void
    {
        file_put_contents($this->mockRoot() . '/sessions.json', json_encode($sessions, JSON_PRETTY_PRINT));
    }

    /** 读取或生成开发密钥对 */
    protected function ensureKeyPair(): array
    {
        $path = $this->mockRoot() . '/market_keys.json';
        if (is_file($path)) {
            $keys = json_decode((string)file_get_contents($path), true);
            if (is_array($keys) && !empty($keys['public'])) {
                return $keys;
            }
        }
        $keys = Security::generateKeyPair();
        file_put_contents($path, json_encode($keys, JSON_PRETTY_PRINT));
        // 私钥明文落 runtime：收紧到仅属主可读写（runtime 已在 .gitignore 且不在 web 根下）
        @chmod($path, 0600);
        return $keys;
    }

    /** 签发 License（付费应用） */
    protected function issueLicense(array $app, array $context): array
    {
        $keys = $this->ensureKeyPair();
        $licenseType = (string)($app['license_type'] ?? 'perpetual');
        $expiresAt = 0;
        if ($licenseType === 'subscription') {
            $expiresAt = time() + (int)($app['license_period'] ?? 31536000);
        }

        $license = [
            'license_id' => 'lic_' . substr(md5($app['name'] . ($context['instance_id'] ?? '')), 0, 16),
            'plugin' => $app['name'],
            'version' => $app['version'],
            'instance_id' => (string)($context['instance_id'] ?? ''),
            'domain' => (string)($context['domain'] ?? ''),
            'license_type' => $licenseType,
            'issued_at' => time(),
            'expires_at' => $expiresAt,
            'alg' => $keys['alg'],
        ];
        $license['signature'] = Security::sign(Security::canonicalPayload($license), $keys['private'], $keys['alg']);
        return $license;
    }

    /** 确保安装包存在（不存在则基于脚手架生成，复用不重建；包内不含签名，完整性走清单） */
    protected function ensurePackage(array $app): string
    {
        $packageDir = $this->mockRoot() . '/packages';
        Directory::ensureDir($packageDir);
        $zipPath = $packageDir . '/' . $app['name'] . '_' . $app['version'] . '.zip';

        // 已生成且比源码模板新则直接复用
        if (is_file($zipPath)) {
            return $zipPath;
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展，无法生成 Mock 安装包');
        }

        $workDir = $packageDir . '/build_' . $app['name'] . '_' . uniqid();
        $pluginDir = $workDir . '/' . $app['name'];
        Directory::ensureDir($pluginDir);

        try {
            // 1. 基于 stub 脚手架生成插件
            $manifest = [
                'name' => $app['name'],
                'title' => $app['title'],
                'version' => $app['version'],
                'support_version' => '1.0.0',
                'description' => $app['description'],
                'author' => $app['author'],
                'type' => 1,
                'is_dev' => 0,
                'is_custom' => 0,
                'open_mode' => 'micro',
                'entry' => '/app/' . $app['name'],
                'manage_entry' => '/app/' . $app['name'],
                'path' => 'plugin/' . $app['name'],
                'dependencies' => [],
                'frontend_routes' => [],
                'hook' => 'plugin\\' . $app['name'] . '\\Plugin',
            ];
            FileService::renderCustomPluginScaffold(
                $pluginDir,
                ['name' => $app['name'], 'title' => $app['title']],
                $manifest,
                $manifest['entry'],
                true
            );

            // 2. 打包（zip 内根目录为插件名，与 findPackageSourceDir 约定一致）
            //    不写包内 signature.json：与真实市场同口径，完整性由 releaseManifest() 签发的清单承担
            FileService::compressDirectory($workDir, $zipPath, '');

            return $zipPath;
        } finally {
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageDir);
            }
        }
    }

    /** 确保主程序 Mock 增量包存在（不存在则生成只含演示文件的 zip，复用不重建） */
    protected function ensureSystemMockPackage(string $version): string
    {
        $packageDir = $this->mockRoot() . '/system_packages';
        Directory::ensureDir($packageDir);
        $zipPath = $packageDir . '/atrman_' . $version . '_delta.zip';
        if (is_file($zipPath)) {
            return $zipPath;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展，无法生成 Mock 主程序包');
        }

        $workDir = $packageDir . '/build_' . uniqid();
        $srcDir = $workDir . '/atrman';
        Directory::ensureDir($srcDir . '/var');
        try {
            file_put_contents(
                $srcDir . '/var/core-upgrade-mock.txt',
                "Atrman Mock 主程序增量包 {$version}\n仅用于升级链路联调，生成于 " . date('c') . "\n"
            );
            file_put_contents(
                $srcDir . '/upgrade.json',
                json_encode(['version' => $version, 'changed_files' => ['var/core-upgrade-mock.txt']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            // zip 内根目录为 atrman，与 findPackageSourceDir 单顶层目录约定一致
            FileService::compressDirectory($workDir, $zipPath, '');
            return $zipPath;
        } finally {
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageDir);
            }
        }
    }
}
