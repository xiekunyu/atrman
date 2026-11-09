/**
 * HTTP 请求封装
 *
 * 复用框架内 `@/utils/http`（自动携带 token、401 处理、错误提示、重试等能力）。
 *
 * 关于免登：token 在 qiankun mount 阶段由 main.ts 从基座同步写入 userStore
 * （syncBridgeUserToStore），框架请求拦截器会直接从 userStore 读取 accessToken
 * 注入 Authorization 头，**无需每个请求再通过 bridge 向基座获取**。
 *
 * @example
 * ```ts
 * import { request } from '@/sdk'
 *
 * // GET（返回 data 字段）
 * const data = await request.get('/app/am_demo/info')
 *
 * // POST
 * const result = await request.post('/app/am_demo/save', { name: 'test' })
 * ```
 */
import type { AxiosRequestConfig } from 'axios'
import api from '@/utils/http'

/**
 * 便捷请求方法（URL 优先签名，底层走框架统一拦截器，返回 data 字段）
 */
export const request = {
  get<T = any>(url: string, config?: AxiosRequestConfig): Promise<T> {
    return api.get<T>({ url, ...config })
  },
  post<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
    return api.post<T>({ url, data, ...config })
  },
  put<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
    return api.put<T>({ url, data, ...config })
  },
  delete<T = any>(url: string, config?: AxiosRequestConfig): Promise<T> {
    return api.del<T>({ url, ...config })
  }
}

/** 框架请求实例（config 对象签名，需更细控制时直接使用） */
export default api
