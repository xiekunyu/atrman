import request from '@/utils/http'

export default {
  /** 获取操作日志列表 */
  list(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/sys/log/index',
      params,
      decompress: true
    })
  },

  /** 获取操作日志详情 */
  read(id: number) {
    return request.get<Api.Common.ApiData>({
      url: `/sys/log/read?id=${id}`
    })
  }
}
