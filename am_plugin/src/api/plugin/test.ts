import pluginRequest from '@/utils/http/plugin'

/**
 * am_im 插件接口测试 API
 *
 * 演示 pluginRequest：所有 URL 都省略 `/app/am_im` 前缀，由插件层自动拼接。
 * 后端真实路径由控制器类级 #[RouteGroup('/app/am_im')] + 方法级注解拼接而成。
 */
export default {
  /** GET /app/am_im/test/ping */
  ping() {
    return pluginRequest.get<{
      pluginName: string
      serverTime: string
      timestamp: number
    }>({
      url: '/test/ping'
    })
  },

  /** GET /app/am_im/test/info */
  info() {
    return pluginRequest.get<{
      name: string
      title: string
      version: string
      author: string
    }>({
      url: '/test/info'
    })
  },

  /** GET /app/am_im/test/currentUser */
  currentUser() {
    return pluginRequest.get<{
      id: number
      name: string
      avatar: string
    }>({
      url: '/test/currentUser'
    })
  },

  /** POST /app/am_im/test/echo */
  echo(data: Record<string, any>) {
    return pluginRequest.post<{
      received: Record<string, any>
      serverTime: string
      count: number
    }>({
      url: '/test/echo',
      data
    })
  },

  /** GET /app/am_im/test/list */
  list(params: { page: number; pageSize: number; keyword?: string }) {
    return pluginRequest.get<{
      list: Array<{ id: number; name: string; status: number; created_at: string }>
      total: number
      page: number
      pageSize: number
      keyword: string
    }>({
      url: '/test/list',
      params
    })
  }
}
