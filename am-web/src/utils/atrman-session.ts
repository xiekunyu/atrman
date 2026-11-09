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

const READ_SKEW_MS = 30 * 1000
const FALLBACK_TTL_MS = 30 * 60 * 1000

export interface AtrmanSession {
  accessToken: string
  refreshToken: string
  user: Record<string, any>
  expiresAt: number
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
    if (!s?.accessToken) {
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
