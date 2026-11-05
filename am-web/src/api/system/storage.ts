import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 存储 API
 */
export default {
  ...createCrudApi('/sys/storage'),

  /** 保存存储配置 */
  saveStorage(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/storage/saveStorage',
      data
    })
  }
}
