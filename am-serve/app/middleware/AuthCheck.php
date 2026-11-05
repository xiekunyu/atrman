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
    public function process(Request $request, callable $handler) : Response
    {
        // 安装路由无需登录（InstallGuard 未生效时的安全兜底）
        $path = '/' . ltrim($request->path(), '/');
        if (str_starts_with($path, '/api/install/')) {
            return $handler($request);
        }

        // 获取控制器哪些方法不需要登录
        $noNeedLogin = ReflectionCache::getNoNeedLogin($request->controller);
        // 访问的方法需要登录
        if (in_array($request->action, $noNeedLogin)) {
             return $handler($request);
        }
        // 登录信息检查
        // 先确认密钥真的装配上了：JWT_ACCESS_KEY 缺失时配置取到 null，用空串密钥签发的令牌
        // 能被空串密钥验通过，任何登录态校验都会静默放行伪造的超管令牌，这里必须 fail-fast。
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