import api from '@/utils/http'
import type { RemotePostOptions } from '@/types/remote-vue'

const PROTOCOL_RE = /^[a-z][a-z\d+\-.]*:/i

const assertAllowedRemotePostUrl = (url: string) => {
  if (typeof url !== 'string' || !url.trim()) {
    throw new Error('远程请求地址不能为空')
  }

  const value = url.trim()
  if (PROTOCOL_RE.test(value) || value.startsWith('//')) {
    throw new Error('远程请求仅支持基座相对路径')
  }

  if (!value.startsWith('/')) {
    throw new Error('远程请求地址必须以 / 开头')
  }

  const path = value.split(/[?#]/)[0]
  const decodedPath = decodeURIComponent(path)
  if (decodedPath.split('/').includes('..')) {
    throw new Error('远程请求地址不允许包含路径穿越')
  }
}

const post = <T = any>(url: string, data?: unknown, options: RemotePostOptions = {}) => {
  assertAllowedRemotePostUrl(url)

  return api.post<T>({
    url: url.trim(),
    data,
    showSuccessMessage: options.showSuccessMessage,
    showErrorMessage: options.showErrorMessage
  })
}

const get = <T = any>(url: string, options: RemotePostOptions = {}) => {
  assertAllowedRemotePostUrl(url)

  return api.get<T>({
    url: url.trim(),
    showSuccessMessage: options.showSuccessMessage,
    showErrorMessage: options.showErrorMessage
  })
}

export const createRemoteRequest = () =>
  Object.freeze({
    post,
    get
  })
