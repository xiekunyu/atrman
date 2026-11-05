import { createCrudApi } from '@/api/crud'
import request from '@/utils/http'

/**
 * 短信服务商 API
 */
export default {
  ...createCrudApi('/notify/sms-provider'),
  changeStatus(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/notify/sms-provider/changeStatus',
      data
    })
  },
  setDefault(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/notify/sms-provider/setDefault',
      data
    })
  },
  test(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/notify/sms-provider/test',
      data
    })
  },
  all() {
    return request.get<any[]>({
      url: '/notify/sms-provider/all'
    })
  }
}
