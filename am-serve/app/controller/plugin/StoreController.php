<?php

namespace app\controller\plugin;

use app\basic\BaseController;
use app\exception\ApiException;
use app\service\CoreUpgradeService;
use app\service\Permission;
use app\service\StoreService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Request;
use support\Response;

/**
 * 应用商店控制器
 *
 * 提供应用市场浏览、安装、更新，以及市场账号绑定、付费应用授权管理功能。
 * 市场数据全部来自 StoreProvider（mock/remote），不在本地落库；
 * 安装/更新的下载凭证只在后端流转，前端拿不到下载地址。
 */
class StoreController extends BaseController
{
    // ─────────────────────── 应用浏览与安装 ───────────────────────
#[Get('/plugin/store/index')]
    #[Permission(title: '应用列表', slug: 'system:store:index')]
    public function index(Request $request): Response
    {
        $result = StoreService::getList($request->get());
        return success('', $result['list'], $result['total'], $result['page']);
    }
#[Get('/plugin/store/read')]
    #[Permission(title: '应用详情', slug: 'system:store:read')]
    public function read(Request $request): Response
    {
        $appId = (string)$request->get('id', '');
        if ($appId === '') {
            return warning('参数错误');
        }

        $detail = StoreService::getDetail($appId);
        if (!$detail) {
            return warning('应用不存在');
        }
        return success('', $detail);
    }
#[Get('/plugin/store/categories')]
    #[Permission(title: '分类列表', slug: 'system:store:categories')]
    public function categories(Request $request): Response
    {
        return success('', StoreService::getCategories());
    }
#[Get('/plugin/store/versions')]
    #[Permission(title: '版本历史', slug: 'system:store:read')]
    public function versions(Request $request): Response
    {
        $appId = (string)$request->get('app_id', '');
        if ($appId === '') {
            return warning('参数错误');
        }
        return success('', StoreService::getVersions($appId));
    }
#[Post('/plugin/store/install')]
    #[Permission(title: '安装应用', slug: 'system:store:install')]
    public function install(Request $request): Response
    {
        $appId = (string)$request->post('id', '');
        if ($appId === '') {
            return warning('参数错误');
        }

        // 支持指定版本安装（空字符串表示最新版）
        $version = (string)$request->post('version', '');
        $result = StoreService::install($appId, $version);
        if (!$result['success']) {
            // 付费未购买：以结构化数据返回购买引导（warning 会丢弃 data，前端拿不到 buy_url）
            if (!empty($result['data']['need_purchase'])) {
                return success($result['message'], $result['data']);
            }
            return warning($result['message']);
        }
        return success($result['message'], $result['data']);
    }
#[Post('/plugin/store/update')]
    #[Permission(title: '更新应用', slug: 'system:store:update')]
    public function update(Request $request): Response
    {
        $appId = (string)$request->post('id', '');
        if ($appId === '') {
            return warning('参数错误');
        }

        // cascade=true：走级联升级（中心插件被启用依赖方卡住时，叶子先停→升级→逆序重启，失败全量回滚）
        if (filter_var($request->post('cascade', false), FILTER_VALIDATE_BOOLEAN)) {
            $result = StoreService::cascadeUpdate($appId);
            // 空标题避开拦截器自动 toast（失败/回滚不能弹绿色提示），文案携在 data.message 由前端自行分支
            return success('', array_merge($result['data'] ?? [], ['message' => (string)$result['message']]));
        }

        // 非级联：保持原逻辑
        $result = StoreService::update($appId);
        if (!$result['success']) {
            if (!empty($result['data']['need_purchase'])) {
                return success($result['message'], $result['data']);
            }
            return warning($result['message']);
        }
        return success($result['message'], $result['data']);
    }
#[Get('/plugin/store/update/preflight')]
    #[Permission(title: '升级预检', slug: 'system:store:update')]
    public function updatePreflight(Request $request): Response
    {
        $appId = (string)$request->get('id', '');
        if ($appId === '') {
            return warning('参数错误');
        }
        return success('', StoreService::updatePreflight($appId));
    }
#[Get('/plugin/store/check-update')]
    #[Permission(title: '检查更新', slug: 'system:store:checkUpdate')]
    public function checkUpdate(Request $request): Response
    {
        return success('', StoreService::checkAllUpdates());
    }
#[Get('/plugin/store/system-update')]
    #[Permission(title: '系统更新检查', slug: 'system:store:systemUpdate')]
    public function systemUpdate(Request $request): Response
    {
        return success('', StoreService::checkSystemUpdate());
    }

    /**
     * 生成主程序升级计划（增量链选包 + 合并依赖/迁移/差异文件概览，不改动任何文件）
     */
    #[Get('/plugin/store/system-update/plan')]
    #[Permission(title: '主程序升级计划', slug: 'system:store:systemUpdatePlan')]
    public function systemUpdatePlan(Request $request): Response
    {
        try {
            return success('', CoreUpgradeService::plan());
        } catch (ApiException $e) {
            return warning($e->getMessage());
        }
    }

    /**
     * 执行主程序升级（投递异步升级任务；仅超管，升级期间站点进入维护模式）
     */
    #[Post('/plugin/store/system-update/execute')]
    #[Permission(title: '执行主程序升级', slug: 'system:store:systemUpdateExecute')]
    public function systemUpdateExecute(Request $request): Response
    {
        // 用 RBAC 超管判定，不用 JWT 的 is_admin：后者由身份类别 account_type 得出，
        // 任何「管理员身份但无角色」的账号都为真，会让本应仅限超管的核心升级越权通过
        if (!isSuperAdmin((int)($this->userInfo['id'] ?? 0))) {
            return warning('仅超级管理员可执行主程序升级');
        }

        $strategy = (string)$request->post('strategy', 'merged');
        try {
            $job = CoreUpgradeService::execute($strategy, (int)($this->userInfo['id'] ?? 0));
        } catch (ApiException $e) {
            return warning($e->getMessage());
        }
        return success('升级任务已提交，正在后台执行', $job);
    }
#[Get('/plugin/store/system-update/status')]
    #[Permission(title: '主程序升级进度', slug: 'system:store:systemUpdateStatus')]
    public function systemUpdateStatus(Request $request): Response
    {
        $jobId = trim((string)$request->get('job_id', ''));
        if ($jobId === '') {
            return warning('参数错误');
        }
        $status = CoreUpgradeService::status($jobId);
        if (!$status) {
            return warning('任务不存在或已过期');
        }
        return success('', $status);
    }

    // ─────────────────────── 市场账号 ───────────────────────
#[Post('/plugin/store/market/login')]
    #[Permission(title: '绑定市场账号', slug: 'system:store:market:login')]
    public function marketLogin(Request $request): Response
    {
        $username = trim((string)$request->post('username', ''));
        $password = (string)$request->post('password', '');

        try {
            $status = StoreService::marketLogin($username, $password);
        } catch (ApiException $e) {
            return warning($e->getMessage());
        }
        return success('绑定成功', $status);
    }
#[Get('/plugin/store/market/status')]
    #[Permission(title: '市场账号状态', slug: 'system:store:market:status')]
    public function marketStatus(Request $request): Response
    {
        return success('', StoreService::marketStatus());
    }
#[Post('/plugin/store/market/logout')]
    #[Permission(title: '解绑市场账号', slug: 'system:store:market:logout')]
    public function marketLogout(Request $request): Response
    {
        StoreService::marketLogout();
        return success('解绑成功', StoreService::marketStatus());
    }

    // ─────────────────────── 授权管理（付费应用） ───────────────────────
#[Get('/plugin/store/license/index')]
    #[Permission(title: '授权列表', slug: 'system:store:license:index')]
    public function licenseIndex(Request $request): Response
    {
        return success('', StoreService::licenseList());
    }
#[Post('/plugin/store/license/refresh')]
    #[Permission(title: '刷新授权', slug: 'system:store:license:refresh')]
    public function licenseRefresh(Request $request): Response
    {
        $pluginId = (int)$request->post('id', 0);
        if ($pluginId <= 0) {
            return warning('参数错误');
        }

        try {
            $result = StoreService::refreshLicense($pluginId);
        } catch (ApiException $e) {
            return warning($e->getMessage());
        }

        if (!$result['valid']) {
            return warning($result['message'], $result);
        }
        return success($result['message'], $result);
    }
}