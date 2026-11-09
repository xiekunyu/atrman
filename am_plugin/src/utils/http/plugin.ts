/**
 * 插件专用请求封装
 *
 * 自动为 URL 添加 `/app/{VITE_PLUGIN_NAME}` 前缀，让插件开发者只写业务路径即可。
 * 复用 utils/http 里同一个 axios 实例，token、错误处理、重试、401 登出等逻辑完全一致。
 *
 * ## 使用场景
 *
 * - 插件自己的后端接口（Webman 应用插件默认 url 前缀，路由组 `/app/{appName}` 下的接口）→ 用 `pluginRequest`
 * - 少数需要访问基座接口的场景（如 `/sys/user/current`）→ 继续用 `request`
 *
 * @example
 * // 实际请求：/api/app/am_im/order/list
 * pluginRequest.get({ url: '/order/list', params: { page: 1 } })
 *
 * @example
 * // 实际请求：/api/app/am_im/order/save
 * pluginRequest.post({ url: '/order/save', data: { ... } })
 *
 * @module utils/http/plugin
 */

import request from './index'
import { getPluginName } from '@/utils/env'

/** 生成插件路径前缀（与后端控制器类级 #[RouteGroup('/app/{appName}')] 约定对齐） */
const pluginPrefix = () => `/app/${getPluginName()}`

/** 给 URL 拼接插件前缀（已带前缀的路径不重复拼接） */
function withPrefix(url = ''): string {
  const prefix = pluginPrefix()
  if (url.startsWith(prefix)) return url
  if (url.startsWith('/')) return prefix + url
  return `${prefix}/${url}`
}

/**
 * 插件请求方法集合：与 utils/http 导出的 request 用法完全一致
 * 仅差别在于 url 会自动加 `/app/{VITE_PLUGIN_NAME}` 前缀
 */
const apiMethods = ['get', 'post', 'put', 'del'] as const

type PluginRequest = {
  [K in (typeof apiMethods)[number]]: <T = any>(config: any) => Promise<T>
} & {
  /** 通用请求（自动拼接插件前缀） */
  request: <T = any>(config: any) => Promise<T>
}

const pluginRequest = apiMethods.reduce((acc, method) => {
  acc[method] = <T = any>(config: any) =>
    (request as any)[method]({ ...config, url: withPrefix(config.url) })
  return acc
}, {} as PluginRequest)

pluginRequest.request = <T = any>(config: any) =>
  request.request({ ...config, url: withPrefix(config.url) })

export default pluginRequest

/** 需要临时构造完整插件路径时可导出该函数（一般用不到） */
export { withPrefix as buildPluginUrl }
