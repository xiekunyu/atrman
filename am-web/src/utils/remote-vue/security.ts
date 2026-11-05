import { hash } from 'ohash'
import type { RemoteVueComponentMeta, RemoteVueSecurityConfig } from '@/types/remote-vue'
import { getApiBaseURL } from '@/utils/env'

// 生产环境跟随当前访问域名，避免构建产物绑定写死的 API 域名
export const REMOTE_VUE_PROXY_PREFIX = getApiBaseURL()

const PROXY_URL = import.meta.env.VITE_API_PROXY_URL || ''
const isClient = typeof window !== 'undefined'
const apiUrl = isClient ? new URL(REMOTE_VUE_PROXY_PREFIX || '/', window.location.origin) : null
const proxyUrl = isClient && PROXY_URL ? new URL(PROXY_URL, window.location.origin) : null
const apiPrefix = apiUrl?.pathname.replace(/\/$/, '') || ''
const allowedOrigins = isClient
  ? [...new Set([window.location.origin, apiUrl?.origin, proxyUrl?.origin].filter(Boolean) as string[])]
  : []

export const getRemoteVueRequestUrl = (url: string): string => {
  if (!isClient) return url

  const parsedUrl = new URL(url, window.location.origin)

  if (!import.meta.env.DEV) return parsedUrl.toString()

  const isProxyUrl = !proxyUrl || parsedUrl.origin === proxyUrl.origin
  if (parsedUrl.origin !== window.location.origin && !isProxyUrl) return parsedUrl.toString()

  return `${window.location.origin}${apiPrefix}${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`
}

export const defaultRemoteVueSecurityConfig: RemoteVueSecurityConfig = {
  enabled: true,
  allowedOrigins,
  allowedPathPrefixes: ['/app/', apiPrefix && `${apiPrefix}/`].filter(Boolean) as string[],
  allowDevHttp: import.meta.env.DEV
}

const DANGEROUS_PROTOCOLS = new Set(['javascript:', 'data:', 'blob:', 'file:'])

const isLocalhost = (hostname: string) =>
  hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1'

export const resolveRemoteVueUrl = (input: string, base?: string): string => {
  const value = input.trim()
  if (!value) {
    throw new Error('远程 Vue 地址不能为空')
  }

  if (typeof window === 'undefined') return value

  return new URL(value, base || window.location.origin).toString()
}

export const assertAllowedRemoteVueUrl = (
  url: string,
  config: RemoteVueSecurityConfig = defaultRemoteVueSecurityConfig
): void => {
  if (!config.enabled || typeof window === 'undefined') return

  const parsedUrl = new URL(url, window.location.origin)

  if (DANGEROUS_PROTOCOLS.has(parsedUrl.protocol)) {
    throw new Error(`不允许加载 ${parsedUrl.protocol} 协议的远程 Vue`)
  }

  if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
    throw new Error(`不支持的远程 Vue 协议：${parsedUrl.protocol}`)
  }

  if (parsedUrl.protocol === 'http:' && !isLocalhost(parsedUrl.hostname)) {
    const isSameHttpOrigin = parsedUrl.origin === window.location.origin
    if (!config.allowDevHttp && !isSameHttpOrigin) {
      throw new Error('生产环境不允许加载非本地 HTTP 远程 Vue')
    }
  }

  if (config.allowedOrigins.length > 0 && !config.allowedOrigins.includes(parsedUrl.origin)) {
    throw new Error(`远程 Vue 域名未加入白名单：${parsedUrl.origin}`)
  }

  if (
    config.allowedPathPrefixes.length > 0 &&
    !config.allowedPathPrefixes.some((prefix) => parsedUrl.pathname.startsWith(prefix))
  ) {
    throw new Error(`远程 Vue 路径未加入白名单：${parsedUrl.pathname}`)
  }
}

export const getRemoteVueCacheKey = (meta: RemoteVueComponentMeta): string => {
  const version = meta.hash || meta.version || ''
  const identity = [meta.plugin, meta.name, version].filter(Boolean).join(':')
  if (identity && version) return `remote-vue:${identity}`

  return `remote-vue:${hash({
    plugin: meta.plugin,
    name: meta.name,
    url: meta.url,
    version: meta.version,
    hash: meta.hash
  })}`
}

export const normalizeRemoteVueMeta = (
  meta: RemoteVueComponentMeta | string,
  name = 'RemoteVue'
): RemoteVueComponentMeta => {
  if (typeof meta === 'string') {
    return {
      name,
      url: meta
    }
  }

  return meta
}
