/**
 * HTTP 请求封装模块
 * 基于 Axios 封装的 HTTP 请求工具，提供统一的请求/响应处理
 *
 * ## 主要功能
 *
 * - 请求/响应拦截器（自动添加 Token、统一错误处理）
 * - 401 未授权自动登出（带防抖机制）
 * - 请求失败自动重试（可配置）
 * - 统一的成功/错误消息提示
 * - 支持 GET/POST/PUT/DELETE 等常用方法
 *
 * @module utils/http
 * @author Art Design Pro Team
 */

import axios, { AxiosRequestConfig, AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { useUserStore } from '@/store/modules/user'
import { ApiStatus } from './status'
import { HttpError, handleError, showError, showSuccess, showWarning } from './error'
import { $t } from '@/locales'
import { BaseResponse } from '@/types'
import { getApiBaseURL } from '@/utils/env'
import { writeSession, emitSessionRefreshed, onSessionRefreshed } from '@/utils/atrman-session'

/** 请求配置常量 */
const REQUEST_TIMEOUT = 15000
const LOGOUT_DELAY = 500
const MAX_RETRIES = 0
const RETRY_DELAY = 1000
const UNAUTHORIZED_DEBOUNCE_TIME = 3000

/** 401防抖状态 */
let isUnauthorizedErrorShown = false
let unauthorizedTimer: NodeJS.Timeout | null = null

/** 扩展 AxiosRequestConfig */
interface ExtendedAxiosRequestConfig extends AxiosRequestConfig {
  showErrorMessage?: boolean
  showSuccessMessage?: boolean
}

/** 带重放标记的内部配置（401 续期后重放置位，防刷新失败时无限重试） */
interface RetryableRequestConfig extends InternalAxiosRequestConfig {
  __retried?: boolean
}

const { VITE_WITH_CREDENTIALS } = import.meta.env

/** Axios实例 */
const axiosInstance = axios.create({
  timeout: REQUEST_TIMEOUT,
  baseURL: getApiBaseURL(),
  withCredentials: VITE_WITH_CREDENTIALS === 'true',
  validateStatus: (status) => status >= 200 && status < 300,
  transformResponse: [
    (data, headers) => {
      const contentType = headers['content-type']
      if (contentType?.includes('application/json')) {
        try {
          return JSON.parse(data)
        } catch {
          return data
        }
      }
      return data
    }
  ]
})

/** 请求拦截器 */
axiosInstance.interceptors.request.use(
  (request: InternalAxiosRequestConfig) => {
    const { accessToken } = useUserStore()
    if (accessToken) request.headers.set('Authorization', accessToken)

    if (request.data && !(request.data instanceof FormData) && !request.headers['Content-Type']) {
      request.headers.set('Content-Type', 'application/json')
      request.data = JSON.stringify(request.data)
    }

    return request
  },
  (error) => {
    showError(createHttpError($t('httpMsg.requestConfigError'), ApiStatus.error))
    return Promise.reject(error)
  }
)

/** 响应拦截器 */
axiosInstance.interceptors.response.use(
  (response: AxiosResponse<BaseResponse>) => {
    const { code, msg } = response.data
    if (code === ApiStatus.success) return response
    if (code === ApiStatus.unauthorized) {
      // 信封级 401（HTTP 200 包裹）：同样进入续期/重放流程
      return handleUnauthorized(msg, response.config as RetryableRequestConfig)
    }
    throw createHttpError(msg || $t('httpMsg.requestFailed'), code)
  },
  (error) => {
    if (error.response?.status === ApiStatus.unauthorized && error.config) {
      return handleUnauthorized(undefined, error.config as RetryableRequestConfig)
    }
    return Promise.reject(handleError(error))
  }
)

/** 统一创建HttpError */
function createHttpError(message: string, code: number) {
  return new HttpError(message, code)
}

/**
 * 401 自动续期（single-flight）：并发请求同时 401 时只发一次 refresh，
 * 其余请求挂在同一个 Promise 上，拿到新令牌后各自重放原请求；
 * 无 refresh 令牌、续期失败或重放后仍 401（__retried）才走原有强退。
 * 注意：SSE/裸 fetch（如流式对话、文件下载）不经过 axios 实例，天然不参与重放。
 */
let refreshInFlight: Promise<void> | null = null

/** 调基座 /pub/auth/refresh 轮转令牌对，并重写 atrman:session 让同域其它标签经 storage 事件跟随 */
async function doRefreshSession(): Promise<void> {
  const userStore = useUserStore()
  const oldRefresh = userStore.refreshToken
  if (!oldRefresh) throw new Error('missing refresh token')
  // 裸 axios 直发，绕过本实例拦截器：refresh 自身的 401 不能再进续期分支（否则递归）
  const resp = await axios.post(
    `${getApiBaseURL()}/pub/auth/refresh`,
    null,
    {
      timeout: REQUEST_TIMEOUT,
      headers: { Authorization: oldRefresh },
      withCredentials: VITE_WITH_CREDENTIALS === 'true'
    }
  )
  const envelope = resp.data as BaseResponse<{ accessToken: string; refreshToken?: string }>
  const next = envelope?.data
  if (envelope?.code !== ApiStatus.success || !next?.accessToken) {
    throw new Error('refresh rejected')
  }
  const nextRefresh = next.refreshToken || oldRefresh
  userStore.setToken(next.accessToken, nextRefresh)
  writeSession({
    accessToken: next.accessToken,
    refreshToken: nextRefresh,
    user: (userStore.info || {}) as Record<string, any>
  })
  // 同步同标签的 qiankun 子应用/基座：它们各自持有独立 pinia，收不到 storage 事件
  emitSessionRefreshed({ accessToken: next.accessToken, refreshToken: nextRefresh })
}

/** single-flight 入口：同一时刻的多个 401 复用同一个刷新 Promise */
function refreshSession(): Promise<void> {
  if (!refreshInFlight) {
    refreshInFlight = doRefreshSession().finally(() => {
      refreshInFlight = null
    })
  }
  return refreshInFlight
}

// 同标签内其它应用（qiankun 基座/兄弟插件）完成轮转时同步本应用 store，
// 避免本应用持有已吊销的 refresh，下次 401 刷新失败被强退
onSessionRefreshed(({ accessToken, refreshToken }) => {
  const userStore = useUserStore()
  if (accessToken && accessToken !== userStore.accessToken) {
    userStore.setToken(accessToken, refreshToken)
  }
})

/** 401 总入口：能续期则重放，否则强退 */
async function handleUnauthorized(message: string | undefined, config: RetryableRequestConfig): Promise<any> {
  if (!config.__retried && useUserStore().refreshToken) {
    try {
      await refreshSession()
      config.__retried = true
      config.headers.set('Authorization', useUserStore().accessToken)
      return axiosInstance.request(config)
    } catch {
      // refresh 过期/被吊销/网络异常：落到强退语义
    }
  }
  return forceLogout(message)
}

/** 续期失败后的强退（带防抖，保持既有交互） */
function forceLogout(message?: string): never {
  const error = createHttpError(message || $t('httpMsg.unauthorized'), ApiStatus.unauthorized)

  if (!isUnauthorizedErrorShown) {
    isUnauthorizedErrorShown = true
    logOut()

    unauthorizedTimer = setTimeout(resetUnauthorizedError, UNAUTHORIZED_DEBOUNCE_TIME)

    showError(error, true)
    throw error
  }

  throw error
}

/** 重置401防抖状态 */
function resetUnauthorizedError() {
  isUnauthorizedErrorShown = false
  if (unauthorizedTimer) clearTimeout(unauthorizedTimer)
  unauthorizedTimer = null
}

/** 退出登录函数 */
function logOut() {
  setTimeout(() => {
    useUserStore().logOut()
  }, LOGOUT_DELAY)
}

/** 是否需要重试 */
function shouldRetry(statusCode: number) {
  return [
    ApiStatus.requestTimeout,
    ApiStatus.internalServerError,
    ApiStatus.badGateway,
    ApiStatus.serviceUnavailable,
    ApiStatus.gatewayTimeout
  ].includes(statusCode)
}

/** 请求重试逻辑 */
async function retryRequest<T>(
  config: ExtendedAxiosRequestConfig,
  retries: number = MAX_RETRIES
): Promise<T> {
  try {
    return await request<T>(config)
  } catch (error) {
    if (retries > 0 && error instanceof HttpError && shouldRetry(error.code)) {
      await delay(RETRY_DELAY)
      return retryRequest<T>(config, retries - 1)
    }
    throw error
  }
}

/** 延迟函数 */
function delay(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** 请求函数 */
async function request<T = any>(config: ExtendedAxiosRequestConfig): Promise<T> {
  // POST | PUT 参数自动填充
  if (
    ['POST', 'PUT'].includes(config.method?.toUpperCase() || '') &&
    config.params &&
    !config.data
  ) {
    config.data = config.params
    config.params = undefined
  }
  let returnData=true
  if(config?.decompress ===true){
    returnData=false
    delete config.decompress;
    
  }
  try {
    const res = await axiosInstance.request<BaseResponse<T>>(config)

    // 显示成功消息
    if (res.data.msg){
      if (res.data.code === ApiStatus.success) {
        showSuccess(res.data.msg)
      } else {
        showWarning(res.data.msg)
      }
    }
    if(returnData){
      return res.data.data as T
    }else{
      return res.data as T
    }
  } catch (error) {
    if (error instanceof HttpError && error.code !== ApiStatus.unauthorized) {
      const showMsg = config.showErrorMessage !== false
      showError(error, showMsg)
    }
    return Promise.reject(error)
  }
}

/** API方法集合 */
const api = {
  get<T>(config: ExtendedAxiosRequestConfig) {
    return retryRequest<T>({ ...config, method: 'GET' })
  },
  post<T>(config: ExtendedAxiosRequestConfig) {
    return retryRequest<T>({ ...config, method: 'POST' })
  },
  put<T>(config: ExtendedAxiosRequestConfig) {
    return retryRequest<T>({ ...config, method: 'PUT' })
  },
  del<T>(config: ExtendedAxiosRequestConfig) {
    return retryRequest<T>({ ...config, method: 'DELETE' })
  },
  request<T>(config: ExtendedAxiosRequestConfig) {
    return retryRequest<T>(config)
  }
}

export default api
