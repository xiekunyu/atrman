import request from '@/utils/http'

export default {
  list(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/sys/storage/index',
      params
    })
  }
}
