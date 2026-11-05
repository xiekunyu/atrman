import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 菜单 API
 */
export default {
  ...createCrudApi('/sys/menu'),

  /** 获取菜单树 */
  getMenuTree() {
    return request.get<Api.Common.ApiData>({
      url: `/sys/menu/getMenuTree`
    })
  },
  /**
   * 扫描控制器同步按钮权限节点（只补按钮，不动目录与页面）
   * @param params prune=1 时同时删除代码里已不存在的按钮节点
   */
  syncPermission(params: Record<string, any> = {}) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/menu/syncPermission',
      data: params
    })
  }
}
