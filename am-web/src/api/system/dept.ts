import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 部门 API
 */
export default {
  ...createCrudApi('/sys/dept', { list: { decompress: true } }),

  /** 设置部门领导 */
  setLeader(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/dept/setLeader',
      data
    })
  }
}
