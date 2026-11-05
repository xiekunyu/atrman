import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 角色 API
 */
export default {
  ...createCrudApi('/sys/role'),

  /** 获取角色权限 */
  getPermissions(id: number) {
    return request.get<Api.Common.ApiData>({
      url: `/sys/role/getPermissions?id=${id}`
    })
  },
  /** 设置角色权限 */
  setPermissions(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/role/setPermissions',
      data
    })
  },
  /** 获取所有角色列表（用于选择） */
  getAllRoles() {
    return request.get<Api.Common.ApiData>({
      url: '/sys/role/all'
    })
  },
  /** 获取可操作角色 */
  accessRole() {
    return request.get<Api.Common.ApiData>({
      url: '/sys/role/accessRole'
    })
  },
  /** 获取角色的用户列表 */
  getUsers(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: `/sys/role/getUsers`,
      params
    })
  }
}
