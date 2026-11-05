<?php

namespace app\controller;

use app\basic\EventRegistry;
use app\basic\JwtKeyGuard;
use app\basic\JwtRevoker;
use app\model\system\User;
use app\utils\Str;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\model\notify\NotifyMessage;
use app\utils\Captcha;
use app\utils\ClientInfo;
use support\Redis;
use tinywan\jwt\JwtToken;
use app\basic\BaseController;
use support\think\Cache;
use Webman\Event\Event;

#[RouteGroup('/pub/auth')]
class AuthController extends BaseController
{
     /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['captcha', 'login'];

    /** 登录失败计数 key 前缀（按 IP+账号 分桶，避免单账号被锁死全站） */
    private const FAIL_KEY_PREFIX = 'atrman:auth:login:fail:';

    /** 窗口内允许的连续失败次数 */
    private const FAIL_LIMIT = 10;

    /** 失败计数窗口（秒） */
    private const FAIL_WINDOW = 600;

    /** 凭据错误的统一文案：区分「用户不存在」会让账号枚举直接跑起来 */
    private const FAIL_MESSAGE = '账号或密码错误';

     /**
     * 获取验证码
     */
    #[Post('/captcha')]
    public function captcha() : Response
    {
        $captcha = new Captcha();
        $result = $captcha->imageCaptcha();
        if ($result['result'] !== 1) {
            return error($result['message']);
        }
        return success('',$result);
    }

    /**
     * 登录
     * @param Request $request
     * @return Response
     */
    #[Post('/login')]
    public function login(Request $request): Response
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        $code = $request->post('code', '');
        $uuid = $request->post('uuid', '');
        $captcha = new Captcha();
        if (!$captcha->checkCaptcha($uuid, $code)) {
            $this->recordLoginLog($request, $username, 2, '验证码错误');
            return warning('验证码错误');
        }
        if (empty($username) || empty($password)) {
            $this->recordLoginLog($request, $username, 2, '用户名和密码不能为空');
            return warning('用户名和密码不能为空');
        }
        // 验证码通过后再看失败次数：先把「每次都要解验证码」的成本压在攻击者身上，
        // 再对同一 IP+账号 的连续猜密收口。
        $failKey = self::FAIL_KEY_PREFIX . md5(ClientInfo::clientIp($request) . '|' . mb_strtolower((string) $username));
        if ($this->loginFailCount($failKey) >= self::FAIL_LIMIT) {
            $this->recordLoginLog($request, $username, 2, '登录失败次数达到上限，已暂时拒绝');
            return warning('登录失败次数过多，请稍后再试');
        }
        $user=new User();
        $userInfo = $user->where(['username'=>$username,'status'=>1])->find();
        if (!$userInfo) {
            $this->increaseLoginFail($failKey);
            $this->recordLoginLog($request, $username, 2, '用户不存在');
            return warning(self::FAIL_MESSAGE);
        }
        // 验证密码
        if (!passwordVerify($password, $userInfo->password, $userInfo->salt)) {
            $this->increaseLoginFail($failKey);
            $this->recordLoginLog($request, $username, 2, '密码错误');
            return warning(self::FAIL_MESSAGE);
        }
        $this->clearLoginFail($failKey);

        $userInfo->last_login_time = time();
        $userInfo->last_login_ip = \app\utils\ClientInfo::clientIp($request);
        $userInfo->save();

        $payload = [
            'id' => $userInfo->id,
            'sid' => $userInfo->sid,
            'username' => $userInfo->username,
            'nickname' => $userInfo->nickname,
            'avatar' => $userInfo->avatar,
            'gender' => $userInfo->gender,
            'motto' => $userInfo->motto,
            'email' => $userInfo->email,
            'mobile' => $userInfo->mobile,
            'account_type' => (int) $userInfo->account_type,
            // is_admin 为便捷标记（由身份类别 account_type 得出，超管 id=1 恒为真）；
            // 能否操作后台具体功能仍由 RBAC 角色决定，权威判定以查库为准。
            'is_admin' => (int) $userInfo->id === 1 || (int) $userInfo->account_type === \app\model\system\User::ACCOUNT_ADMIN,
            'exp' => time() + 7200,
            // 会话标识：退出登录时按它写吊销名单（见 JwtRevoker）。access/refresh 共用一个，退出即整对失效。
            'jti' => Str::random(16),
        ];

        JwtKeyGuard::assertConfigured();
        $token = JwtToken::generateToken($payload);
        $data= [
            'accessToken' => $token['token_type'].' '.$token['access_token'],
            'refreshToken' => $token['token_type'].' '.$token['refresh_token'],
            'user' => $payload,
            'unreadNotifyCount' => NotifyMessage::getUnreadCount($userInfo->id),
            ];
            
        $this->recordLoginLog($request, $username, 1, '登录成功');
        EventRegistry::emit('user.loggedin', [
            'user_id' => (int)$userInfo->id,
            'sid' => (string)$userInfo->sid,
            'username' => $username,
        ]);
        return success('登录成功',$data);
    }

    #[Post('/logout')]
    public function logout(Request $request)
    {
        // 只清前端 localStorage 等于没退出：令牌在 exp 前照样能直接调接口，这里按 jti 写入吊销名单
        $info = getCurrentInfo();
        if ($info !== false) {
            JwtRevoker::revoke((string)($info['jti'] ?? ''));
        }
        return success('退出登录成功');
    }

    public function info(Request $request)
    {
        $token = $request->header('token');
        $token = str_replace('Bearer ', '', $token);
        $tokenInfo = JwtToken::getExtend($token);
        if (!$tokenInfo) {
            return warning('token错误');
        }
        return success('',$tokenInfo);
    }

    /** 窗口内已失败次数。Redis 不可用时返回 0：缓存故障不应把管理员锁在后台外面。 */
    private function loginFailCount(string $key): int
    {
        try {
            return (int) Redis::get($key);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** 记一次失败：首次失败时设窗口 TTL；incr 成功但 expire 没跟上（连接中途断了）会留下永不过期的键，把该 IP+账号永久锁死，所以补一次 ttl<0 的修复。 */
    private function increaseLoginFail(string $key): void
    {
        try {
            $count = (int) Redis::incr($key);
            if ($count === 1 || (int) Redis::ttl($key) < 0) {
                Redis::expire($key, self::FAIL_WINDOW);
            }
        } catch (\Throwable) {
        }
    }

    private function clearLoginFail(string $key): void
    {
        try {
            Redis::del($key);
        } catch (\Throwable) {
        }
    }

    private function recordLoginLog(Request $request, string $username, int $status, string $message): void
    {
        Event::emit('user.loginLog', [
            'request' => $request,
            'username' => $username,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
