import request from '@/utils/http'

export default {
  /** 获取登录日志列表 */
  list(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/sys/loginLog/index',
      params
    })
  },

  /** 获取登录日志详情 */
  read(id: number) {
    return request.get<Api.Common.ApiData>({
      url: `/sys/loginLog/read?id=${id}`
    })
  }
}
