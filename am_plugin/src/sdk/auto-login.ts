/**
 * 前台独立入口的内置免登（插件开发者零对接）
 *
 * 仅在**独立运行**（非 qiankun、无 Bridge）时启用——即门户里新标签打开 /app/{name} 的前台场景。
 * 后台（manage）经基座 qiankun 加载，登录态由 Bridge 注入，走 main.ts 的 hasBridge() 分支，与本模块无关。
 *
 * 时序（本地优先 → 票据兜底）：
 *   1) readSession()：同域已有有效会话 → 直接水合到 userStore，无需网络；
 *   2) 否则读 URL 一次性 ticket → 调基座兑换主 JWT → 写 atrman:session 并水合 → 抹掉 URL 票据；
 *   3) 都拿不到 → 保持未登录，前台公开页照常可浏览。
 *
 * 另注册 storage 监听，实现跨标签登录态一致（任一同域端登出/换号，本端同步）。
 *
 * @module sdk/auto-login
 */
import { useUserStore } from '@/store/modules/user'
import { getPluginName } from '@/utils/env'
import { fetchTicketExchange } from '@/api/auth-sso'
import {
  ATRMAN_SESSION_KEY,
  readSession,
  writeSession,
  readTicketFromUrl,
  stripTicketFromUrl,
  type AtrmanSession
} from '@/utils/atrman-session'

/** JWT user.avatar 可能是对象（File 关系）或字符串，统一成可展示 URL（与会员端口径一致） */
function pickAvatar(avatar: any): string {
  if (!avatar) return ''
  if (typeof avatar === 'string') return avatar
  const url = String(avatar.url || '').replace(/\/$/, '')
  const path = String(avatar.path || '')
  return path ? `${url}/${path.replace(/^\//, '')}` : url
}

/** 把统一会话水合进框架 userStore（令牌供 HTTP 拦截器注入 Authorization） */
function hydrate(session: AtrmanSession): void {
  const userStore = useUserStore()
  userStore.setToken(session.accessToken, session.refreshToken)
  userStore.setLoginStatus(true)
  const u = session.user || {}
  userStore.setUserInfo({
    ...u,
    avatar: pickAvatar(u.avatar)
  } as Api.Auth.UserInfo)
}

/** 依据当前会话刷新内存态：有则登录、无则清空（供跨标签同步与登出复用） */
function applyOrClear(session: AtrmanSession | null): void {
  const userStore = useUserStore()
  if (session) {
    hydrate(session)
    return
  }
  userStore.setLoginStatus(false)
  userStore.$patch({ accessToken: '', refreshToken: '', info: {} } as any)
}

/**
 * 免登引导：必须在 initStore(app) 之后、initRouter(app) 之前调用，
 * 以保证首次路由导航/守卫执行前登录态已就绪。
 */
export async function bootstrapStandaloneAuth(): Promise<void> {
  // 1) 本地优先
  const local = readSession()
  if (local) {
    hydrate(local)
    return
  }

  // 2) 票据兜底
  const ticket = readTicketFromUrl()
  if (!ticket) return // 无本地会话且无票据：保持未登录，前台公开浏览

  try {
    const res = await fetchTicketExchange(ticket, getPluginName())
    const session = writeSession(res)
    if (session) hydrate(session)
  } catch (e) {
    // 兑换失败（票据过期/已用/aud 不符）→ 静默降级为公开浏览，不阻断前台渲染
    console.warn('[Plugin] 免登票据兑换失败，降级为公开浏览：', e)
  } finally {
    // 无论成败都抹掉票据：一次性票据重试也必然失败，留地址栏只会误导分享
    stripTicketFromUrl()
  }
}

let syncRegistered = false

/**
 * 注册跨标签会话同步：其它同域端（基座/会员中心/别的插件）登录或登出时，本端即时跟随。
 */
export function setupSessionSync(): void {
  if (syncRegistered) return
  syncRegistered = true
  window.addEventListener('storage', (e) => {
    if (e.key !== ATRMAN_SESSION_KEY) return
    if (!e.newValue) {
      applyOrClear(null)
      return
    }
    try {
      applyOrClear(JSON.parse(e.newValue) as AtrmanSession)
    } catch {
      applyOrClear(null)
    }
  })
}

// 会员中心导航/登录态能力统一收口在 sdk/member；此处透传，兼容 `@/sdk/auto-login` 旧导入路径
export {
  openMemberLogin,
  openMemberRegister,
  openMemberCenter,
  memberLoginUrl,
  memberRegisterUrl,
  memberFrontUrl,
  isLoggedIn,
  requireLogin,
  logoutLocal,
  handleSessionExpired,
  onUnauthorized,
  normalizeRedirect,
  currentHashPath
} from './member'
