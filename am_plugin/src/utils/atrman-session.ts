/**
 * 跨端统一会话契约 atrman:session
 *
 * 基座(am-web)、会员中心(am_member)、各前台插件(am_plugin 体系)在生产环境同域部署，
 * 天然共享同一份 localStorage。本模块定义**唯一**的会话存储契约：一个与框架无关、
 * 不带版本前缀的固定键 `atrman:session`，形状取自登录/兑换响应(accessToken/refreshToken/user)。
 *
 * 免登时序（本地优先 → 票据兜底）：
 *   1) 任一端登录成功 → writeSession() 写入 atrman:session；
 *   2) 其它同域端启动 → readSession() 命中有效会话即直接复用，无需票据；
 *   3) 读不到(首次/过期/不同浏览器配置) → 用 URL 上的一次性 ticket 兑换主 JWT 后再 writeSession()。
 *
 * 该键独立于各端 pinia 的 `sys-v{版本}-user` 持久化（版本来自各 app 构建时间戳，端间不同、
 * 无法互读），是"所有端用户信息保存一致"的唯一真源。
 *
 * @module utils/atrman-session
 */

/** 跨端统一会话键（固定、无版本前缀，务必与各端保持一致） */
export const ATRMAN_SESSION_KEY = 'atrman:session'

/**
 * 契约版本号：形状（字段集合/语义）发生不兼容变更时 +1。
 * 读取时缺失 v 字段视为历史 v1（升级部署不强制全员重登）；未知高版本一律拒绝并清理。
 */
export const ATRMAN_SESSION_VERSION = 1

/** 读取时提前量（毫秒）：距过期不足该窗口即视为失效，避免临界态用到将过期令牌 */
const READ_SKEW_MS = 30 * 1000

/** 解析不出 JWT exp 时的兜底有效期（毫秒） */
const FALLBACK_TTL_MS = 30 * 60 * 1000

export interface AtrmanSession {
  /** 契约版本，写入时盖 ATRMAN_SESSION_VERSION */
  v: number
  accessToken: string
  refreshToken: string
  user: Record<string, any>
  /** 过期时刻（毫秒时间戳），取自主 JWT 的 exp */
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

/** base64url 解码为 UTF-8 字符串（兼容中文昵称等多字节） */
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

/**
 * 解析 JWT payload 的 exp（秒）→ 毫秒时间戳。容忍 'Bearer ' 前缀；无效返回 null。
 */
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

/**
 * 写入统一会话：由 accessToken 的 exp 推导 expiresAt，供各端一致地判断有效性。
 * @returns 规范化后的会话对象；accessToken 缺失时返回 null（不写脏数据）
 */
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
    /* 隐私模式 / 配额异常：忽略，不影响本端内存登录态 */
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

/**
 * 从当前 URL 读取一次性票据：兼容 search(?ticket=) 与 hash query(#/xx?ticket=) 两种投递形态。
 * （TicketService::attachToUrl 拼在应用入口地址上，入口是否带 hash 决定了 ticket 落在哪一段）
 */
export function readTicketFromUrl(): string {
  try {
    const fromSearch = new URLSearchParams(window.location.search).get('ticket')
    if (fromSearch) return fromSearch
    const hash = window.location.hash || ''
    const qi = hash.indexOf('?')
    if (qi >= 0) {
      const fromHash = new URLSearchParams(hash.slice(qi + 1)).get('ticket')
      if (fromHash) return fromHash
    }
  } catch {
    /* ignore */
  }
  return ''
}

/**
 * 兑换成功后抹掉 URL 上的 ticket，避免票据残留在地址栏 / 被复制分享 / 刷新时重复兑换。
 * 用 replaceState 不新增历史、不触发路由重解析。
 */
export function stripTicketFromUrl(): void {
  try {
    const { pathname, search, hash } = window.location
    let nextSearch = search
    if (new URLSearchParams(search).has('ticket')) {
      const sp = new URLSearchParams(search)
      sp.delete('ticket')
      const q = sp.toString()
      nextSearch = q ? `?${q}` : ''
    }
    let nextHash = hash
    if (hash.includes('ticket=')) {
      const qi = hash.indexOf('?')
      if (qi >= 0) {
        const hpath = hash.slice(0, qi)
        const hp = new URLSearchParams(hash.slice(qi + 1))
        if (hp.has('ticket')) {
          hp.delete('ticket')
          const q = hp.toString()
          nextHash = q ? `${hpath}?${q}` : hpath
        }
      }
    }
    window.history.replaceState({}, document.title, pathname + nextSearch + nextHash)
  } catch {
    /* ignore */
  }
}
