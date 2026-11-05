import { router } from '@/router'
import type { PluginBridgeContext } from '../types'

export const createNavigationBridgeService = (context: PluginBridgeContext) => ({
  setTitle(params: { title?: string } | string = {}) {
    const title = typeof params === 'string' ? params : params.title
    if (!title) return false
    context.setTitle?.(title)
    return true
  },

  async openPlugin(params: any = {}) {
    if (!params.name) {
      throw new Error('打开插件需要插件标识 name')
    }

    // 入口路由去 ID 化：仅按插件标识 name 解析（记录 id 不随备份迁移保持，不能作为路由依据）。
    await router.push({
      name: 'PluginApp',
      params: { name: params.name },
      query: {
        ...(params.entry ? { entry: params.entry } : {}),
        ...(params.title ? { title: params.title } : {})
      }
    })
    return true
  },

  async openRoute(params: any = {}) {
    const path = typeof params === 'string' ? params : params.path
    if (!path || !String(path).startsWith('/')) {
      throw new Error('只能打开内部相对路由')
    }

    await router.push({
      path,
      query: params.query || {}
    })
    return true
  }
})
