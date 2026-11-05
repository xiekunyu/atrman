import request from '@/utils/http'

/** 会员中心票据兑换返回：与基座 fetchLogin 同构，可直接写入 userStore */
export interface SsoExchangeResult {
  accessToken: string
  refreshToken: string
  user: Api.Auth.UserInfo
}

/**
 * 会员中心票据兑换（公开接口，票据一次性、aud=am_web、60s 过期）
 *
 * 走 am-web 的 request：dev 命中 /api 代理，prod 命中当前域名 origin，均落到
 * 同域后端 `/app/am_member/sso/exchange`（该端点在插件侧 $noNeedLogin=['exchange'] 白名单内）。
 *
 * @param ticket 会员登录页签发、回跳时携带的一次性票据
 */
export function fetchSsoExchange(ticket: string) {
  return request.post<SsoExchangeResult>({
    url: '/app/am_member/sso/exchange',
    params: { client: 'am_web', ticket },
    // 兑换失败由页面自行提示并引导重登，抑制拦截器的重复错误弹窗
    showErrorMessage: false
  })
}
