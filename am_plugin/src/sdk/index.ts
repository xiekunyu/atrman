/**
 * 插件 SDK 统一导出
 *
 * 插件页面中可从 @/sdk 导入所有 SDK 能力：
 *
 * @example
 * ```ts
 * import { useUser, invoke, request, getBridge, getCapabilities } from '@/sdk'
 * ```
 */

// Bridge 管理
export { setBridge, getBridge, hasBridge, invoke, getCapabilities } from './bridge'

// HTTP 请求
export { default as http, request } from './http'

// 用户状态
export { useUser, initUser, refreshUser, resetUser } from './user'
export type { PluginUserInfo } from './user'

// 入口模式（双入口架构）
export { setEntryMode, getEntryMode, isFrontEntry, resolveEntryMode, setPluginMeta, getPluginMeta } from './entry'
export type { EntryMode } from './entry'

// 免登票据兑换 / 跨标签会话同步
export { bootstrapStandaloneAuth, setupSessionSync } from './auto-login'

// 会员中心（am_member）对接：登录/注册同页签闭环、个人中心新标签、登录态守门、401 事件总线
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
  emitUnauthorized,
  normalizeRedirect,
  currentHashPath
} from './member'
