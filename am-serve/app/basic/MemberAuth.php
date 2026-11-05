<?php

namespace app\basic;

use support\Request;
use support\Response;

/**
 * 前台插件会员态鉴权 Trait
 *
 * 基座 AuthCheck 中间件只校验 JWT 是否有效，不区分后台账号（account_type=1）与会员（account_type=2）；
 * 一方前台插件（am_plugin 体系）的控制器常把公开接口与会员接口放在同一控制器，需要自行决定每个
 * action 的鉴权姿势。本 Trait 统一"会员态"的解析口径与错误体，避免各插件各抄一份 Bearer 校验：
 *
 *   姿势① 公开：action 写入 $noNeedLogin，不调用本 Trait；
 *   姿势② 必登录（前台混合控制器推荐）：action 写入 $noNeedLogin 绕过 AuthCheck，
 *         方法首行 `if ($resp = $this->requireMember($request)) return $resp;`，未登录返回业务形 401；
 *   姿势③ 纯管理接口：action 不写白名单，由 AuthCheck 直接拦截，配合 #[Permission] 做 RBAC。
 *
 * 身份解析统一走 getCurrentInfo()（tinywan/jwt 的 extend 段），与 AuthCheck 同口径；
 * 不自行解析 Authorization 头，避免刷新黑名单/取 token 方式漂移。
 *
 * 本类不硬依赖 am_member：仅 memberOnly=true 时才调用 MemberService，且用 class_exists 保护，
 * 会员中心未安装时按"非会员"处理。
 */
trait MemberAuth
{
    /** 本次请求解析出的身份（null=未解析，空数组=匿名） */
    protected ?array $memberContextData = null;

    /** 当前会员/账号 sid，未鉴权为空串 */
    protected string $memberSid = '';

    /**
     * 解析当前身份并归一化（同一请求内只解析一次）。
     * 优先取 AuthCheck 已注入的 $request->userInfo，白名单 action 回落 getCurrentInfo()。
     * @return array{sid:string,id:int,username:string,nickname:string,avatar:mixed,account_type:int,is_admin:int}|null
     */
    protected function memberContext(Request $request): ?array
    {
        if ($this->memberContextData !== null) {
            return $this->memberContextData ?: null;
        }

        // AuthCheck 对非白名单 action 会把 JWT extend 注入到该动态属性
        $raw = isset($request->userInfo) && is_array($request->userInfo)
            ? $request->userInfo
            : getCurrentInfo();

        if (!is_array($raw) || empty($raw['sid'])) {
            $this->memberContextData = [];
            $this->memberSid = '';
            return null;
        }

        $ctx = [
            'sid'          => (string)$raw['sid'],
            'id'           => (int)($raw['id'] ?? 0),
            'username'     => (string)($raw['username'] ?? ''),
            'nickname'     => (string)($raw['nickname'] ?? ''),
            'avatar'       => $raw['avatar'] ?? '',
            'account_type' => (int)($raw['account_type'] ?? 0),
            'is_admin'     => (int)($raw['is_admin'] ?? 0),
        ];
        $this->memberContextData = $ctx;
        $this->memberSid = $ctx['sid'];
        return $ctx;
    }

    /**
     * 要求登录态。
     * @param bool $memberOnly true 时额外要求是 am_member 有效会员（后台账号不放行）
     * @return Response|null 通过返回 null；失败返回统一 401/403 响应（调用方直接 return）
     */
    protected function requireMember(Request $request, bool $memberOnly = false): ?Response
    {
        $ctx = $this->memberContext($request);
        if ($ctx === null) {
            return $this->memberUnauthorized('请先登录');
        }
        if ($memberOnly && !$this->isValidMember($ctx['sid'])) {
            return json([
                'code' => 403,
                'msg'  => '当前账号无权访问会员内容',
                'data' => null,
            ])->withStatus(403);
        }
        return null;
    }

    /**
     * 可选鉴权：携带合法 JWT 则解析身份，未携带/失效一律按匿名放行。
     * 公开列表"登录后个性化"场景使用；之后用 $this->sid() 判空。
     */
    protected function optionalMember(Request $request): void
    {
        $this->memberContext($request);
    }

    /** 当前登录主体 sid（未登录返回空串） */
    protected function sid(): string
    {
        return $this->memberSid;
    }

    /** 是否为 am_member 有效会员；会员中心未安装/未启用时恒为 false，不致命 */
    protected function isValidMember(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }
        $memberService = 'plugin\\am_member\\app\\service\\MemberService';
        if (!class_exists($memberService)) {
            return false;
        }
        return $memberService::isMember($sid);
    }

    /** 统一业务形 401（HTTP 状态码同为 401，前端 http 拦截器可识别） */
    protected function memberUnauthorized(string $msg = '请先登录'): Response
    {
        return json([
            'code' => 401,
            'msg'  => $msg,
            'data' => null,
        ])->withStatus(401);
    }
}
