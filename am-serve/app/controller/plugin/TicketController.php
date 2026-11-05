<?php

namespace app\controller\plugin;

use app\basic\BaseController;
use app\model\plugin\App as Plugin;
use app\model\system\User;
use app\service\IdentityService;
use app\service\PluginManifestService;
use app\service\TicketService;
use support\annotation\route\Post;
use support\Request;
use support\Response;
use Tinywan\Jwt\JwtToken;

/**
 * 免登票据控制器（基座侧）
 *
 * 面向"应用门户/工作台"打开应用的免登透传：
 * - issue：基座前端在用户点击某个应用（entry 普通使用 / manage_entry 管理入口）时调用，
 *   为当前登录主体签发一次性 ticket，并把带 ticket 的目标地址回给前端跳转。
 * - consume：应用后端用 ticket 换取登录主体的 sid，建立自身会话。
 *
 * 边界：基座只保证 sid 可靠透传与 ticket 不可伪造/不可重放，绝不判定应用内权限；
 * "这个人在本应用里能做什么"完全由应用开发者实现。
 */
class TicketController extends BaseController
{
    /**
     * 不需要登录的接口
     * - consume：应用后端服务器间调用，凭 HMAC 签名的 ticket 换取 sid，无需基座登录态。
     * - exchange：同源前台插件用 ticket 直接兑换主 JWT（凭一次性、aud 绑定、短时效的票据自证），无需基座登录态。
     */
    protected array $noNeedLogin = ['consume', 'exchange'];

    /**
     * 签发免登 ticket（需基座登录态）
     * POST /oauth/ticket/issue
     * 参数：app_id（插件标识，必填）；entry（entry|manage_entry，默认 entry）
     */
    #[Post('/oauth/ticket/issue')]
    public function issue(Request $request): Response
    {
        $loginInfo = getCurrentInfo();
        if (!$loginInfo || empty($loginInfo['sid'])) {
            return warning('登录状态失效，无法免登');
        }
        $sid = (string) $loginInfo['sid'];

        $appId = (string) $request->post('app_id', '');
        if ($appId === '') {
            return warning('参数错误：缺少 app_id');
        }

        $entry = $request->post('entry', 'entry') === 'manage_entry' ? 'manage_entry' : 'entry';

        // 定位目标应用
        $plugin = Plugin::where('name', \normalizePluginName($appId))->findOrEmpty();
        if ($plugin->isEmpty() || (int) $plugin->status !== 1) {
            return warning('应用不存在或未启用');
        }

        // 依据入口类型选择目标地址（入口存于 manifest JSON，plugin_app 表无 entry 列；管理入口缺省回落普通入口）
        $manifest = PluginManifestService::getManifest($plugin);
        $frontUrl = (string)($manifest['entry'] ?? '');
        $targetUrl = $entry === 'manage_entry'
            ? ((string)($manifest['manage_entry'] ?? '') ?: $frontUrl)
            : $frontUrl;

        // 签发一次性 ticket（绑定 sid + 应用 + 入口）
        $ticket = TicketService::issue($sid, $plugin->name, $entry);

        return success('', [
            'app_id' => $plugin->name,
            'entry' => $entry,
            'ticket' => $ticket,
            'url' => TicketService::attachToUrl($targetUrl, $ticket),
        ]);
    }

    /**
     * 消费免登 ticket，换取登录主体 sid（应用后端服务器间调用）
     * POST /oauth/ticket/consume
     * 参数：ticket（必填）；app_id（建议携带，校验绑定）；client_secret（三方应用 type=3 建议校验）
     */
    #[Post('/oauth/ticket/consume')]
    public function consume(Request $request): Response
    {
        $ticket = (string) $request->post('ticket', '');
        $appId = (string) $request->post('app_id', '');
        if ($ticket === '') {
            return warning('缺少免登票据');
        }

        // 原子消费：验签 + 时效 + 防重放；若带 app_id 则校验与票据绑定 aud 一致（仅冗余校验，安全判定以下方 payload['app'] 为准）
        $payload = TicketService::consume($ticket, $appId !== '' ? \normalizePluginName($appId) : null);
        if (!$payload) {
            return warning('免登票据无效或已过期');
        }

        $sid = $payload['sid'];

        // 三方应用（type=3）额外校验客户端密钥，确保只有目标应用后端可兑换。
        // 以票据内绑定的 aud 定位应用，避免依赖可省略的请求参数绕过校验
        $boundApp = (string) ($payload['app'] ?? '');
        $plugin = Plugin::where('name', \normalizePluginName($boundApp))->findOrEmpty();
        // 票据绑定的应用必须仍是已安装且启用的应用：卸载或停用后仍能兑换，说明这张票已无人认领
        if ($boundApp === '' || $plugin->isEmpty() || (int) $plugin->status !== 1) {
            return warning('票据绑定的应用不存在或未启用');
        }
        if ((int) $plugin->type === 3) {
            $clientSecret = (string) $request->post('client_secret', '');
            if ($clientSecret === '' || !hash_equals((string) $plugin->oauth_client_secret, $clientSecret)) {
                return warning('应用密钥校验失败');
            }
        }

        // 透传 sid 并附带最小安全资料（非敏感，便于应用渲染；应用内权限由应用自行判定）
        $identity = IdentityService::resolveOne($sid);

        return success('', [
            'sid' => $sid,
            'app' => $payload['app'],
            'entry' => $payload['entry'],
            'identity' => $identity,
        ]);
    }

    /**
     * 前台插件用 ticket 兑换主 JWT（同源免登，插件前端零后端对接）
     * POST /oauth/ticket/exchange
     * 参数：ticket（必填）；app_id（必填，须与票据绑定 aud 一致）
     *
     * 与 consume 的区别：consume 面向应用**后端**服务器间调用（换 sid，client_secret 绝不下放前端）；
     * exchange 面向**同源部署**的一方前台插件（am_plugin 体系），凭一次性票据直接换发与基座同密钥、
     * 同域的主 JWT，插件前端存下即可直接调用基座接口，无需再写会话交换后端。
     *
     * 安全边界：
     *  - 票据一次性、HMAC 不可伪造、60s 过期、aud 绑定 app_id（只能兑自己那张票）；
     *  - 仅签发给已启用的**一方插件**；type=3 三方应用一律拒绝（其无同源前提，必须走 consume + client_secret）。
     */
    #[Post('/oauth/ticket/exchange')]
    public function exchange(Request $request): Response
    {
        $ticket = (string) $request->post('ticket', '');
        $appId = (string) $request->post('app_id', '');
        if ($ticket === '' || $appId === '') {
            return warning('参数错误：缺少 ticket 或 app_id');
        }

        // 目标应用必须是已安装且启用的启用态一方插件
        $plugin = Plugin::where('name', \normalizePluginName($appId))->findOrEmpty();
        if ($plugin->isEmpty() || (int) $plugin->status !== 1) {
            return warning('应用不存在或未启用');
        }
        // 三方应用（type=3）与基座不同源，绝不可拿到主 JWT，只能走服务端 consume
        if ((int) $plugin->type === 3) {
            return warning('第三方应用请使用服务端 consume 接口');
        }

        // 原子消费并校验 aud 绑定：票据只能由签发时绑定的那个应用兑换
        $payload = TicketService::consume($ticket, \normalizePluginName($appId));
        if (!$payload) {
            return warning('免登票据无效或已过期');
        }

        $sid = (string) $payload['sid'];
        $userInfo = User::where('sid', $sid)->where('status', 1)->find();
        if (!$userInfo) {
            return warning('账号不存在或已禁用');
        }

        // 与基座登录同构签发主 JWT（同密钥同域，插件前端可直接复用）
        $tokenPayload = [
            'id' => (int) $userInfo->id,
            'sid' => (string) $userInfo->sid,
            'username' => $userInfo->username,
            'nickname' => $userInfo->nickname,
            'avatar' => $userInfo->avatar,
            'gender' => (int) $userInfo->gender,
            'motto' => (string) $userInfo->motto,
            'email' => (string) $userInfo->email,
            'mobile' => (string) $userInfo->mobile,
            'account_type' => (int) $userInfo->account_type,
            'is_admin' => (int) $userInfo->id === 1 || (int) $userInfo->account_type === User::ACCOUNT_ADMIN,
            'exp' => time() + 7200,
            // 与基座登录同口径：带 jti 才能被退出登录吊销（见 app\basic\JwtRevoker）
            'jti' => \app\utils\Str::random(16),
        ];
        $token = JwtToken::generateToken($tokenPayload);

        return success('', [
            'accessToken' => $token['token_type'] . ' ' . $token['access_token'],
            'refreshToken' => $token['token_type'] . ' ' . $token['refresh_token'],
            'user' => $tokenPayload,
            'app' => $payload['app'],
        ]);
    }
}
