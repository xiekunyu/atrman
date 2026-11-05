import request from '@/utils/http'

export default {
  /** 获取配置 */
  getConfig(params: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/config/getConfig',
      data: params
    })
  }
}
