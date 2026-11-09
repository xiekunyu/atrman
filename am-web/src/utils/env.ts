/**
 * 运行时环境配置
 *
 * 统一「接口请求地址」的取值来源：
 * - 开发模式读取环境变量（走本地 vite 代理）；
 * - 生产模式直接使用当前访问域名，使编译后的产物随部署域名自适应，无需按环境重新编译。
 *
 * @module utils/env
 */

/**
 * 接口请求基础地址
 * - 开发：环境变量 VITE_API_URL（如 /api，由 vite 代理 rewrite 掉前缀后转发到后端）
 * - 生产：当前访问域名 origin（后端接口与前端部署在同域名根路径，无需 /api 前缀，也无需重新编译）
 */
export function getApiBaseURL(): string {
  if (import.meta.env.DEV) {
    return (import.meta.env.VITE_API_URL as string) || ''
  }
  if (typeof window !== 'undefined' && window.location?.origin) {
    return window.location.origin
  }
  return (import.meta.env.VITE_API_URL as string) || ''
}
