import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 用户 API
 */
export default {
  ...createCrudApi('/sys/user', { list: { decompress: true } }),

  /** 修改密码 */
  editPass(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/user/editPass',
      data
    })
  },
  /** 批量设置用户信息 */
  batchSet(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/user/batchSet',
      data
    })
  },
  /** 设置用户部门 */
  setDepts(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/user/setDepts',
      data
    })
  }
}
