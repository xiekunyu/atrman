import request from '@/utils/http'

export default {
  /** 获取缓存监控数据 */
  monitor() {
    return request.get<Api.Common.ApiData>({
      url: '/sys/cache/monitor'
    })
  },

  /** 清理缓存。code 为空时清理所有业务缓存 */
  clear(code = '') {
    return request.post<Api.Common.ApiData>({
      url: '/sys/cache/clear',
      data: { code }
    })
  }
}
