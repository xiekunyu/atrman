import { createCrudApi } from '@/api/crud'
import request from '@/utils/http'

export default {
  ...createCrudApi('/plugin/am_todo'),
  changeStatus(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/am_todo/changeStatus',
      data
    })
  }
}
