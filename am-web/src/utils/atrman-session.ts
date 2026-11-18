/**
 * 跨端统一会话契约 atrman:session（基座 am-web 副本，与 am_plugin / 会员中心 保持一致）
 *
 * 基座、会员中心、各前台插件在生产环境同域部署，天然共享同一份 localStorage。基座登录后写入
 * 本契约，同域插件即可"本地优先"直接复用会话免登；登出清除后经 storage 监听令各端一并失效。
 *
 * ⚠️ 三端各有一份等价实现（分属独立工作区，无共享包）。修改键名/形状务必三端同步。
 *
 * @module utils/atrman-session
 */

export const ATRMAN_SESSION_KEY = 'atrman:session'

/**
 * 契约版本号：形状（字段集合/语义）发生不兼容变更时 +1。
 * 读取时缺失 v 字段视为历史 v1（升级部署不强制全员重登）；未知高版本一律拒绝并清理。
 */
export const ATRMAN_SESSION_VERSION = 1

const READ_SKEW_MS = 30 * 1000
const FALLBACK_TTL_MS = 30 * 60 * 1000

export interface AtrmanSession {
  /** 契约版本，写入时盖 ATRMAN_SESSION_VERSION */
  v: number
  accessToken: string
  refreshToken: string
  user: Record<string, any>
  expiresAt: number
}

/** 会话形状校验：供 storage 监听等自行 JSON.parse 的场景复用，避免读到缺字段/错版本的脏契约 */
export function isValidSession(s: unknown): s is AtrmanSession {
  if (!s || typeof s !== 'object') return false
  const o = s as Record<string, any>
  // 无 v 字段的历史会话按 v1 兼容；出现更高版本说明本端代码过旧，拒绝使用
  if (o.v !== undefined && o.v !== ATRMAN_SESSION_VERSION) return false
  return typeof o.accessToken === 'string' && o.accessToken !== ''
}

function base64UrlDecode(seg: string): string {
  const pad = seg.length % 4 ? '='.repeat(4 - (seg.length % 4)) : ''
  const bin = atob(seg.replace(/-/g, '+').replace(/_/g, '/') + pad)
  return decodeURIComponent(
    bin
      .split('')
      .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
      .join('')
  )
}

/** 解析 JWT payload 的 exp（秒）→ 毫秒；容忍 'Bearer ' 前缀；无效返回 null */
export function parseJwtExpMs(token: string): number | null {
  try {
    const raw = String(token || '').replace(/^Bearer\s+/i, '')
    const parts = raw.split('.')
    if (parts.length < 2) return null
    const payload = JSON.parse(base64UrlDecode(parts[1]))
    const exp = Number(payload?.exp)
    return exp > 0 ? exp * 1000 : null
  } catch {
    return null
  }
}

/** 写入统一会话（由 accessToken 的 exp 推导 expiresAt）；accessToken 缺失返回 null */
export function writeSession(data: {
  accessToken: string
  refreshToken?: string
  user?: Record<string, any>
}): AtrmanSession | null {
  if (!data?.accessToken) return null
  const session: AtrmanSession = {
    v: ATRMAN_SESSION_VERSION,
    accessToken: data.accessToken,
    refreshToken: data.refreshToken || '',
    user: data.user || {},
    expiresAt: parseJwtExpMs(data.accessToken) ?? Date.now() + FALLBACK_TTL_MS
  }
  try {
    localStorage.setItem(ATRMAN_SESSION_KEY, JSON.stringify(session))
  } catch {
    /* ignore */
  }
  return session
}

/** 读取统一会话；不存在或已过期（含提前量）则清理并返回 null */
export function readSession(): AtrmanSession | null {
  try {
    const raw = localStorage.getItem(ATRMAN_SESSION_KEY)
    if (!raw) return null
    const s = JSON.parse(raw) as AtrmanSession
    if (!isValidSession(s)) {
      clearSession()
      return null
    }
    if (s.expiresAt && Date.now() + READ_SKEW_MS >= s.expiresAt) {
      clearSession()
      return null
    }
    return s
  } catch {
    return null
  }
}

/** 清除统一会话（登出时调用，令各同域端一并失去登录态） */
export function clearSession(): void {
  try {
    localStorage.removeItem(ATRMAN_SESSION_KEY)
  } catch {
    /* ignore */
  }
}

/**
 * 同标签令牌轮转事件。
 * storage 事件只在「其它标签」触发，qiankun 同标签内基座与插件各持一份 pinia，
 * 一端轮转令牌（旧 refresh 的 jti 被吊销）后，必须经此事件即时同步给同标签其它应用，
 * 否则它们稍后会用已吊销的 refresh 刷新失败而被强退。
 */
export const ATRMAN_SESSION_REFRESH_EVENT = 'atrman:session-refreshed'

export interface SessionRefreshedDetail {
  accessToken: string
  refreshToken: string
}

/** 广播同标签令牌轮转（http 拦截器刷新成功后调用） */
export function emitSessionRefreshed(detail: SessionRefreshedDetail): void {
  try {
    window.dispatchEvent(new CustomEvent(ATRMAN_SESSION_REFRESH_EVENT, { detail }))
  } catch {
    /* ignore */
  }
}

/** 订阅同标签令牌轮转（返回取消订阅函数）；仅接受带 accessToken 的合法载荷 */
export function onSessionRefreshed(cb: (detail: SessionRefreshedDetail) => void): () => void {
  const handler = (e: Event) => {
    const detail = (e as CustomEvent<SessionRefreshedDetail>).detail
    if (detail?.accessToken) cb(detail)
  }
  window.addEventListener(ATRMAN_SESSION_REFRESH_EVENT, handler)
  return () => window.removeEventListener(ATRMAN_SESSION_REFRESH_EVENT, handler)
}
