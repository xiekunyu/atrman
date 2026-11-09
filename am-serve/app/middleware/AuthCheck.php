<?php
namespace app\middleware;

use app\basic\JwtKeyGuard;
use app\cache\ReflectionCache;
use app\cache\UserAuthCache;
use app\cache\UserInfoCache;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\exception\ApiException;

class AuthCheck implements MiddlewareInterface
{
    /**
     * 鉴权口径：公开前缀与 $noNeedLogin 放行；其余请求要求合法 JWT + 账号存活；
     * 带 #[Permission] 的动作再做 slug RBAC，无注解动作登录即可（会员中心自助接口依赖此口径）。
     * 会员/管理员共用 system_user 与同一 JWT，以 account_type 区分；am_store 的 /api/v1
     * 与开发者中心绕过本中间件自持鉴权，吊销名单在其基类内补查。
     *
     * 公开接口前缀白名单：仅登记「整个前缀 100% 都是公开动作」的路由。
     * 混合前缀（如 /pub/auth 下 logout 要登录、login 不要）由各控制器 $noNeedLogin 精确放行。
     */
    private const PUBLIC_PATH_PREFIXES = [
        '/api/install',
        '/app/am_store/public',
    ];

    public function process(Request $request, callable $handler) : Response
    {
        $path = '/' . ltrim($request->path(), '/');

        // 1) 公开前缀白名单：已知的门户/安装路由直接短路
        foreach (self::PUBLIC_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix . '/') || $path === $prefix) {
                return $handler($request);
            }
        }

        // 2) 控制器自己声明的免登方法（per-method 粒度，覆盖前缀白名单的疏漏）
        $noNeedLogin = ReflectionCache::getNoNeedLogin($request->controller);
        if (in_array($request->action, $noNeedLogin, true)) {
             return $handler($request);
        }

        // 3) JWT 密钥有效性：空串密钥签的 token 会被空串密钥验通过，必须 fail-fast
        JwtKeyGuard::assertConfigured();
        $userInfo = getCurrentInfo();
        if ($userInfo === false) {
            throw new ApiException('用户信息读取失败，无法访问或操作', 401);
        }
        $uid = (int)($userInfo['id'] ?? 0);
        // JWT 纯解码即可通过（见 functions.php getCurrentInfo），已退出登录的令牌由 JwtRevoker 按 jti 拦下；
        // 禁用/删除账号仍只能靠这里回源查存活状态，否则旧 token 在 exp 前仍可调用无注解动作。
        $info = UserInfoCache::getUserInfo($uid);
        if (empty($info) || (int)($info['status'] ?? 0) !== 1) {
            throw new ApiException('账号不存在或已被禁用', 401);
        }
        $request->userInfo = $userInfo;
        if (isSuperAdmin($uid)) {
             return $handler($request);
        }

        // 验证权限
        $permission = ReflectionCache::getPermissionAttributes($request->controller, $request->action);
        $slug = (string)($permission['slug'] ?? '');
        if ($slug === '') {
            return $handler($request);
        }

        $auth = UserAuthCache::getUserAuth($uid);
        // 超管由 UserAuthCache 以 SUPER_AUTH(['*']) 哨兵返回，这里只认哨兵通配
        if (in_array('*', $auth, true) || in_array($slug, $auth, true)) {
            return $handler($request);
        }

        throw new ApiException('无权限访问或操作', 403);
    }
}