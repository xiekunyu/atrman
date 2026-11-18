import request from '@/utils/http'

export default {
  /** 获取服务器监控数据 */
  monitor() {
    return request.get<Api.Common.ApiData>({
      url: '/sys/server/monitor'
    })
  }
}
