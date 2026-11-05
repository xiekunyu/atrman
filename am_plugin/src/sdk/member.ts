/**
 * 会员中心（am_member）对接 SDK
 *
 * 插件没有也不应有独立的登录/注册页：需要身份时一律跳会员中心前台（同域 /app/am_member）。
 * 登录/注册走【同页签票据闭环】，个人中心走新标签（跨应用浏览，无需自动回流）：
 *
 *   1) openMemberLogin()：整页跳 /app/am_member#/login?client=plugin:{本插件}&redirect={当前hash深链}
 *   2) 会员中心登录成功 → 调基座 POST /oauth/ticket/issue 签一次性票据 → 整页回跳本插件 entry?ticket=
 *   3) 回到本插件后由 sdk/auto-login 的 bootstrapStandaloneAuth() 兑换主 JWT 并落在 redirect 深链
 *
 * 安全约定：回跳基址只信任服务端 issue 返回的 manifest entry；redirect 永远只是本插件 hash 片段，
 * 不参与 # 前的 URL 拼接（会员端同样白名单校验），杜绝 open-redirect。
 *
 * 仅同域部署（生产形态）成立：登录态靠同域 localStorage 契约 atrman:session 共享。
 * 开发跨端口联调见 .env.development 的 VITE_MEMBER_ENTRY 说明。
 *
 * @module sdk/member
 */
import { qiankunWindow } from 'vite-plugin-qiankun/dist/helper'
import { useUserStore } from '@/store/modules/user'
import { getPluginName, getMemberEntry } from '@/utils/env'
import { clearSession } from '@/utils/atrman-session'

/** client 参数前缀，与会员中心登录/注册页的识别协议保持一致 */
const PLUGIN_CLIENT_PREFIX = 'plugin:'

/** redirect 最大长度（与会员中心 usePluginReturn 的校验口径一致） */
const MAX_REDIRECT_LEN = 200

/**
 * 仅允许"插件内 hash 路径"字符：字母数字、路径分隔符与查询字符（/-_ . ~ % ? & =）。
 * 冒号/反斜杠/@/引号/空白由前置检查拒绝，协议形态（如 /https://evil）无法通过。
 */
const SAFE_REDIRECT_RE = /^\/[A-Za-z0-9\-._~%/&?=]*$/

// ─────────────────────── 401 微型事件总线 ───────────────────────

type UnauthorizedHandler = () => void
const unauthorizedHandlers = new Set<UnauthorizedHandler>()

/** 订阅"登录态失效"事件（RequireLogin 组件挂载时订阅，用于免刷新翻牌） */
export function onUnauthorized(cb: UnauthorizedHandler): () => void {
  unauthorizedHandlers.add(cb)
  return () => unauthorizedHandlers.delete(cb)
}

/** 触发"登录态失效"事件（HTTP 拦截器收到前台 401 时调用） */
export function emitUnauthorized(): void {
  unauthorizedHandlers.forEach((cb) => {
    try {
      cb()
    } catch {
      /* 单个订阅者异常不影响其余 */
    }
  })
}

// ─────────────────────── 地址构造 ───────────────────────

/**
 * 归一化插件内 hash 回跳路径。
 * 合法：以单个 / 开头、非 //、无协议/主机字符、长度受限；非法一律返回 ''（回 entry 根）。
 */
export function normalizeRedirect(redirect?: string): string {
  let r = String(redirect || '').trim()
  if (!r) return ''
  if (!r.startsWith('/') || r.startsWith('//')) return ''
  // 显式拒绝冒号（协议形态）、反斜杠、@、引号与空白
  if (/[\s\\@"':]/.test(r) || r.length > MAX_REDIRECT_LEN) return ''
  if (!SAFE_REDIRECT_RE.test(r)) return ''
  return r
}

/**
 * 取当前插件内 hash 深链作为默认回跳地址。
 * 独立直连（hash 路由）读 location.hash；qiankun 嵌入（memory 路由）取不到 hash，返回 ''（回入口根）。
 */
export function currentHashPath(): string {
  if (qiankunWindow.__POWERED_BY_QIANKUN__) return ''
  const h = window.location.hash || ''
  const raw = h.startsWith('#') ? h.slice(1) : h
  // 抹掉可能残留的 ticket 查询参数（理论上 auto-login 已抹除，双保险避免把旧票带回会员端）
  const clean = raw
    .replace(/([?&])ticket=[^&]*/g, '$1')
    .replace(/[?&]+$/, '')
  return normalizeRedirect(clean)
}

/** 拼会员中心前台 hash 地址，如 memberFrontUrl('/login', {client, redirect}) */
export function memberFrontUrl(hashPath: string, query?: Record<string, string>): string {
  const path = hashPath.startsWith('/') ? hashPath : `/${hashPath}`
  const qs = query
    ? '?' +
      Object.entries(query)
        .filter(([, v]) => v !== '')
        .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
        .join('&')
    : ''
  // 基址不带尾斜杠、# 紧跟其后：多一个 / 会让请求落到 /app/am_member/（历史链接踩过）
  return `${getMemberEntry()}#${path}${qs}`
}

/** 会员中心登录页地址（同页签闭环；redirect 默认当前 hash 深链） */
export function memberLoginUrl(opts: { redirect?: string } = {}): string {
  const redirect = normalizeRedirect(opts.redirect ?? currentHashPath())
  return memberFrontUrl('/login', {
    client: `${PLUGIN_CLIENT_PREFIX}${getPluginName()}`,
    redirect
  })
}

/** 会员中心注册页地址（注册成功即登录，回跳协议与登录一致） */
export function memberRegisterUrl(opts: { redirect?: string } = {}): string {
  const redirect = normalizeRedirect(opts.redirect ?? currentHashPath())
  return memberFrontUrl('/register', {
    client: `${PLUGIN_CLIENT_PREFIX}${getPluginName()}`,
    redirect
  })
}

/**
 * 同页签整页跳会员中心登录页。
 * qiankun 同 window 下 location.assign 即顶层导航；真跨域 iframe 时尝试 top.location，失败降级本层。
 */
export function openMemberLogin(opts: { redirect?: string } = {}): void {
  assignTopLocation(memberLoginUrl(opts))
}

/** 同页签整页跳会员中心注册页 */
export function openMemberRegister(opts: { redirect?: string } = {}): void {
  assignTopLocation(memberRegisterUrl(opts))
}

/**
 * 新标签打开会员中心个人中心（默认"我的会员"页）。
 * 个人资料/账号安全只在会员中心提供，属于跨应用浏览，不需要自动回流，故保留新标签。
 */
export function openMemberCenter(hashPath: string = '/index/center/home'): void {
  const url = memberFrontUrl(hashPath.startsWith('/') ? hashPath : `/${hashPath}`)
  window.open(url, '_blank', 'noopener')
}

/** 顶层整页跳转（兼容跨域 iframe） */
function assignTopLocation(url: string): void {
  try {
    if (window.top && window.top !== window) {
      window.top.location.assign(url)
      return
    }
  } catch {
    // 跨域 iframe 访问 top.location 抛错：降级本层跳转
  }
  window.location.assign(url)
}

// ─────────────────────── 登录态 ───────────────────────

/** 当前是否已登录（pinia 态，由 Bridge 水合或 auto-login 票据兑换写入） */
export function isLoggedIn(): boolean {
  return !!useUserStore().isLogin
}

/**
 * 动作级登录守门：已登录返回 true；未登录整页跳会员登录页并返回 false。
 * @example
 * const onSubmit = () => { if (!requireLogin()) return; ... }
 */
export function requireLogin(redirect?: string): boolean {
  if (isLoggedIn()) return true
  openMemberLogin({ redirect })
  return false
}

/**
 * 本地退出：清跨端统一会话（同域各端经 storage 事件同步登出）并重置本插件登录态。
 * userStore.logOut() 已按入口模式分流（front 仅清理不跳转），直接复用，不调任何后端登出。
 */
export function logoutLocal(): void {
  clearSession()
  useUserStore().logOut()
}

/**
 * 会话失效统一处理（HTTP 拦截器 401 调用）：
 * 清本地会话 → 广播事件让 RequireLogin/页头即时翻牌；是否跳登录页由 UI（通知按钮/门控组件）决定。
 */
export function handleSessionExpired(): void {
  clearSession()
  const userStore = useUserStore()
  userStore.setLoginStatus(false)
  userStore.setToken('', '')
  userStore.setUserInfo({} as Api.Auth.UserInfo)
  emitUnauthorized()
}
