import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 配置 API
 */
export default {
  ...createCrudApi('/sys/config'),

  /** 获取配置 */
  getConfig(params: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/config/getConfig',
      data: params
    })
  },
  /** 更新配置 */
  setConfig(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/config/setConfig',
      data
    })
  },
  /** 保存配置数据 */
  saveConfig(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/config/saveConfig',
      data
    })
  }
}
