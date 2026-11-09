import request from '@/utils/http'

/**
 * 免登票据兑换（基座通用接口，供同源前台插件调用）
 *
 * 走插件的 `request`（baseURL 生产为当前域名 origin、开发为 /api 代理），
 * 命中的是**基座**端点 `/oauth/ticket/exchange`（非 `/app/{name}` 前缀，故用 request 而非 pluginRequest）。
 * 该端点在基座侧 `$noNeedLogin` 白名单内：票据本身即凭证（一次性、aud 绑定 app_id、60s 过期）。
 *
 * @param ticket 门户点击应用时基座签发、拼在跳转 URL 上的一次性票据
 * @param appId  当前插件标识（VITE_PLUGIN_NAME），须与票据绑定 aud 一致
 */
export interface TicketExchangeResult {
  accessToken: string
  refreshToken: string
  user: Record<string, any>
  app: string
}

export function fetchTicketExchange(ticket: string, appId: string) {
  return request.post<TicketExchangeResult>({
    url: '/oauth/ticket/exchange',
    params: { ticket, app_id: appId },
    // 兑换失败由 bootstrap 内部降级为公开浏览，抑制拦截器的重复错误弹窗
    showErrorMessage: false
  })
}
