<?php

namespace app\controller\plugin;

use app\basic\BaseController;
use app\model\plugin\App as Plugin;
use app\service\OAuthService;
use app\service\PluginManifestService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\Request;
use support\Response;

/**
 * OAuth 授权控制器
 * 
 * 实现 OAuth 2.0 授权码流程，支持三方应用获取本系统权限
 * 同时支持 SSO 免登（类似企业微信应用）
 */
class OAuthController extends BaseController
{
    /**
     * 不需要登录的接口
     */
    protected array $noNeedLogin = ['authorize', 'token', 'ssoVerify', 'userInfo'];
    
    /**
     * OAuth 授权入口
     * 
     * 三方应用跳转到此接口发起授权
     * GET /oauth/authorize?client_id=xxx&redirect_uri=xxx&state=xxx&scope=xxx&response_type=code
     */
    #[Get('/oauth/authorize')]
    public function authorize(Request $request): Response
    {
        $clientId = $request->get('client_id', '');
        $redirectUri = $request->get('redirect_uri', '');
        $state = $request->get('state', '');
        $scopes = $request->get('scope', '');
        $responseType = $request->get('response_type', 'code');
        
        // 验证响应类型
        // 回调地址此刻还没经过白名单校验，一律走本地 JSON 错误体：
        // 直接带着请求里的 redirect_uri 跳转等于把一个未经校验的第三方地址交给浏览器（开放重定向）
        if ($responseType !== 'code') {
            return $this->oauthError('', 'unsupported_response_type', '不支持的响应类型', $state);
        }
        
        // 验证客户端
        $plugin = Plugin::where('oauth_client_id', $clientId)
            ->where('type', 3)
            ->where('status', 1)
            ->find();
        
        if (!$plugin) {
            return $this->oauthError('', 'invalid_client', '应用不存在或未启用', $state);
        }
        
        // 验证回调地址
        if (!$this->validateRedirectUri($redirectUri, $plugin->oauth_redirect_uri)) {
            return $this->oauthError('', 'invalid_redirect_uri', '回调地址不合法', $state);
        }
        
        // 已登录用户直接生成授权码（登录态取人主体 sid）
        $loginInfo = getCurrentInfo();
        if ($loginInfo && !empty($loginInfo['sid'])) {
            $code = OAuthService::createAuthCode($clientId, (string) $loginInfo['sid'], $redirectUri, $scopes);
            
            $url = $redirectUri;
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query([
                'code' => $code,
                'state' => $state,
            ]);
            
            return redirect($url);
        }
        
        // 未登录跳转登录页，登录后继续授权
        $currentUrl = $request->fullUrl();
        // 二期：SSO 接管开关（.env: SSO_AMWEB_ENABLED=true）。开启后三方授权未登录流量
        // 也走会员中心登录；票据回跳到 am-web 回调页，回调页再跳回本 URL 完成授权。
        if (env('SSO_AMWEB_ENABLED') === 'true') {
            // 回调页锚在后台入口文件而非站点根 '/'：'/' 会被「网站配置-前端入口」改跳走，换票链接落空。
            // 该入口路径由 SsoService 恒定放行，管理员无需另配回跳白名单。
            $back = adminEntryPath() . '#/auth/sso-callback?redirect=' . urlencode($currentUrl);
            return redirect(authEntry() . '#/login?client=am_web&redirect=' . urlencode($back));
        }
        return redirect('/login?redirect=' . urlencode($currentUrl));
    }
    
    /**
     * 换取访问令牌
     * 
     * 三方应用用授权码换取 access_token，或用 refresh_token 刷新令牌
     * POST /oauth/token
     */
    #[Post('/oauth/token')]
    public function token(Request $request): Response
    {
        $grantType = $request->post('grant_type', '');
        $clientId = $request->post('client_id', '');
        $clientSecret = $request->post('client_secret', '');
        
        // 验证参数
        if (empty($clientId) || empty($clientSecret)) {
            return $this->tokenError('invalid_request', '缺少必要参数');
        }
        
        if ($grantType === 'authorization_code') {
            $code = $request->post('code', '');
            if (empty($code)) {
                return $this->tokenError('invalid_request', '缺少授权码');
            }
            $tokenData = OAuthService::exchangeToken($code, $clientId, $clientSecret);
            if (!$tokenData) {
                return $this->tokenError('invalid_grant', '授权码无效或已过期');
            }
            return json($tokenData);
        }
        
        if ($grantType === 'refresh_token') {
            $refreshToken = $request->post('refresh_token', '');
            if (empty($refreshToken)) {
                return $this->tokenError('invalid_request', '缺少刷新令牌');
            }
            $tokenData = OAuthService::refreshAccessToken($refreshToken, $clientId, $clientSecret);
            if (!$tokenData) {
                return $this->tokenError('invalid_grant', '刷新令牌无效或已过期');
            }
            return json($tokenData);
        }
        
        return $this->tokenError('unsupported_grant_type', '不支持的授权类型');
    }
    
    /**
     * 获取用户信息
     * 
     * 三方应用用 access_token 获取用户信息
     * GET /oauth/userinfo
     * Header: Authorization: Bearer {access_token}
     */
    #[Get('/oauth/userinfo')]
    public function userInfo(Request $request): Response
    {
        $token = $this->extractBearerToken($request);
        if (!$token) {
            return $this->tokenError('invalid_token', '缺少访问令牌');
        }
        
        $tokenInfo = OAuthService::validateToken($token);
        if (!$tokenInfo) {
            return $this->tokenError('invalid_token', '访问令牌无效或已过期');
        }
        
        $userInfo = OAuthService::getUserInfo($tokenInfo['sid'], $tokenInfo['scopes']);
        if (!$userInfo) {
            return $this->tokenError('invalid_token', '用户不存在');
        }
        
        return json($userInfo);
    }
    
    /**
     * 撤销访问令牌
     * 
     * POST /oauth/revoke
     */
    #[Post('/oauth/revoke')]
    public function revoke(Request $request): Response
    {
        $token = $request->post('token', '');
        if (empty($token)) {
            return $this->tokenError('invalid_request', '缺少令牌参数');
        }

        // 只允许撤销自己主体名下的令牌：原先按 token 串直删、不校验归属，
        // 任何登录账号拿到别人的令牌串都能把它打掉（跨主体拒绝服务）。
        // 撤销不动作时仍回 success：RFC 7009 对无效令牌也返回 200，顺带避免令牌归属被枚举。
        $sid = '';
        if (!isSuperAdmin()) {
            $loginInfo = getCurrentInfo();
            $sid = (string)($loginInfo['sid'] ?? '');
            if ($sid === '') {
                return $this->tokenError('invalid_grant', '登录状态失效，无法撤销令牌');
            }
        }
        OAuthService::revokeToken($token, $sid);
        
        return json(['success' => true]);
    }
    
    /**
     * SSO 免登验证
     * 
     * 三方应用调用此接口验证 SSO 凭证，获取用户信息
     * GET /oauth/sso/verify?sso_token=xxx
     */
    #[Get('/oauth/sso/verify')]
    public function ssoVerify(Request $request): Response
    {
        $ssoToken = $request->get('sso_token', '');
        if (empty($ssoToken)) {
            return warning('缺少SSO凭证');
        }
        
        $data = OAuthService::validateSSOToken($ssoToken);
        if (!$data) {
            return warning('SSO凭证无效或已过期');
        }

        // 凭证签发时绑定了 client_id，消费方必须仍是那个已启用的三方应用：
        // 否则应用停用/卸载后，其手中未用尽的凭证仍能继续换用户资料
        $plugin = Plugin::where('oauth_client_id', (string) ($data['client_id'] ?? ''))
            ->where('type', 3)
            ->where('status', 1)
            ->find();
        if (!$plugin) {
            return warning('SSO凭证绑定的应用不存在或未启用');
        }

        $userInfo = OAuthService::getUserInfo($data['sid']);
        if (!$userInfo) {
            return warning('用户不存在');
        }
        
        return success('', $userInfo);
    }
    
    /**
     * 生成 SSO 跳转链接（前端调用）
     * 
     * POST /oauth/sso/url
     */
    #[Post('/oauth/sso/url')]
    public function ssoUrl(Request $request): Response
    {
        $pluginId = $request->post('id', '');
        if (empty($pluginId)) {
            return warning('参数错误');
        }
        
        $plugin = Plugin::where('id', $pluginId)
            ->where('type', 3)
            ->where('status', 1)
            ->find();
        
        if (!$plugin) {
            return warning('应用不存在或未启用');
        }
        
        // 生成 SSO 凭证（登录态取人主体 sid）
        $loginInfo = getCurrentInfo();
        if (!$loginInfo || empty($loginInfo['sid'])) {
            return warning('登录状态失效');
        }
        $ssoToken = OAuthService::createSSOToken((string) $loginInfo['sid'], $plugin->oauth_client_id);
        
        // 构建跳转 URL（入口存于 manifest JSON，plugin_app 表无 entry 列）
        $manifest = PluginManifestService::getManifest($plugin);
        $entryUrl = (string)($manifest['entry'] ?? '') ?: (string)($manifest['manage_entry'] ?? '');
        $separator = str_contains($entryUrl, '?') ? '&' : '?';
        $url = $entryUrl . $separator . http_build_query([
            'sso_token' => $ssoToken,
        ]);
        
        return success('', ['url' => $url]);
    }
    
    /**
     * 验证回调地址是否合法
     */
    protected function validateRedirectUri(string $redirectUri, string $allowedUris): bool
    {
        if (empty($allowedUris)) {
            return false;
        }
        
        $allowed = explode(',', $allowedUris);
        foreach ($allowed as $uri) {
            $uri = trim($uri);
            if ($uri === $redirectUri) {
                return true;
            }
            // 支持通配符匹配（如 https://example.com/*）
            if (str_ends_with($uri, '*')) {
                $prefix = substr($uri, 0, -1);
                if (str_starts_with($redirectUri, $prefix)) {
                    // 边界约束：前缀以 / 结尾，或通配内容从 / ? # 边界开始，
                    // 防止 https://example.com* 被 https://example.com-evil.net 这类伪前缀绕过
                    $rest = substr($redirectUri, strlen($prefix));
                    if ($rest === '' || str_ends_with($prefix, '/') || in_array($rest[0], ['/', '?', '#'], true)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * 提取 Bearer Token
     */
    protected function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }
        
        // 也支持从参数获取
        return $request->get('access_token') ?: null;
    }
    
    /**
     * 返回 OAuth 错误（授权流程）
     */
    protected function oauthError(string $redirectUri, string $error, string $description, string $state = ''): Response
    {
        if (!empty($redirectUri)) {
            $url = $redirectUri;
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query([
                'error' => $error,
                'error_description' => $description,
                'state' => $state,
            ]);
            return redirect($url);
        }
        
        return json([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }
    
    /**
     * 返回 OAuth 错误（令牌接口）
     */
    protected function tokenError(string $error, string $description): Response
    {
        return json([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }
}
