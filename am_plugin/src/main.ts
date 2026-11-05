/**
 * 插件前端入口
 *
 * 融合 Art Design Pro 核心框架 + qiankun 微前端生命周期。
 * 基座加载时通过 qiankun props 注入 Bridge，插件自动继承登录态。
 *
 * 插件开发者通常不需要修改本文件。
 */
import App from './App.vue'
import { createApp } from 'vue'
import { initStore } from './store'
import { initRouter } from './router'
import language from './locales'
import '@styles/core/tailwind.css'
import '@styles/index.scss'
import '@utils/sys/console.ts'
import { setupGlobDirectives } from './directives'
import { setupErrorHandle } from './utils/sys/error-handle'
import '@/utils/ui/iconify-loader'
import { setBridge, hasBridge, invoke } from './sdk/bridge'
import { initUser, resetUser } from './sdk/user'
import { bootstrapStandaloneAuth, setupSessionSync } from './sdk/auto-login'
import { setupBaseThemeSync } from './sdk/theme'
import { setEntryMode, setPluginMeta, resolveEntryMode } from './sdk/entry'
import { useUserStore } from './store/modules/user'
import {
  renderWithQiankun,
  qiankunWindow
} from 'vite-plugin-qiankun/dist/helper'

let app: ReturnType<typeof createApp> | null = null
// 基座主题同步的取消订阅函数（子应用卸载时调用）
let themeUnsub: () => void = () => {}

/**
 * 将 Bridge 用户信息同步到 Art 框架的 userStore
 *
 * 插件模式下，用户登录态由基座管理。
 * 通过 Bridge 获取基座的用户资料和 token，写入 Art 的 store，
 * 使路由守卫、HTTP 拦截器等框架层能正常工作。
 */
const syncBridgeUserToStore = async () => {
  try {
    const [profile, session] = await Promise.all([
      invoke<any>('auth.getUserProfile').catch(() => ({} as any)),
      invoke<any>('auth.getSession').catch(() => ({} as any))
    ])

    const userStore = useUserStore()

    // 设置登录状态
    userStore.setLoginStatus(true)

    // 设置用户信息（兼容 Art 框架 UserInfo 结构）
    if (profile && Object.keys(profile).length > 0) {
      userStore.setUserInfo({
        id: profile.id || profile.userId || '',
        sid: profile.sid || '',
        username: profile.username || '',
        nickname: profile.nickname || '',
        avatar: profile.avatar || '',
        account_type: profile.accountType || profile.account_type,
        is_admin: profile.isAdmin || profile.is_admin
      } as Api.Auth.UserInfo)
    }

    // 设置 token（HTTP 拦截器依赖此字段注入 Authorization 头）
    // auth.getSession 受 manifest 的 auth.session 能力裁剪；未授权时回退基座默认开放的 auth.getAccessToken，保证免登不受能力配置影响
    const accessToken = session?.accessToken || (await invoke<string>('auth.getAccessToken').catch(() => ''))
    if (accessToken) {
      userStore.setToken(accessToken, session?.refreshToken)
    }
  } catch (e) {
    console.warn('[Plugin] 同步 Bridge 用户信息到 Store 失败:', e)
  }
}

/** 创建并挂载应用 */
const createApplication = async (props: any = {}) => {
  // 双入口：先确定入口模式（manage/front），后续路由初始化、守卫、401 行为均据此分支
  setEntryMode(resolveEntryMode(props))
  // 缓存基座传入的插件 meta，供前台页 navigation.openPlugin 跳后台使用
  setPluginMeta(props.plugin)

  // 注入 Bridge（基座通过 qiankun props 传入）
  const bridge = props.hostBridge || props.amBridge
  if (bridge) {
    setBridge(bridge)
  }

  app = createApp(App)
  initStore(app)

  // 插件模式：先从 Bridge 同步基座用户/token 到 Art Store（HTTP 拦截器依赖 accessToken 注入 Authorization）
  if (hasBridge()) {
    await initUser() // SDK 层的用户缓存
    await syncBridgeUserToStore() // 同步到 Art 框架的 store
  } else {
    // 独立运行（新标签直连 /app/{name} 的前台入口，无 Bridge）：内置免登
    // 本地优先读同域 atrman:session → 命中即复用；否则用 URL 一次性 ticket 兑换主 JWT
    await bootstrapStandaloneAuth()
    // 跨标签登录态一致：基座/会员中心/其它插件的登录登出会实时同步到本端
    setupSessionSync()
  }

  // 再初始化路由：内部会拉取插件菜单并注册路由，必须 await 保证首次导航前路由已就绪
  await initRouter(app)

  setupGlobDirectives(app)
  setupErrorHandle(app)
  app.use(language)

  // 主题同步：菜单布局固定为子应用自己的；基座模式下主题实时跟随基座
  themeUnsub = await setupBaseThemeSync()

  const mountEl = props.container
    ? props.container.querySelector('#app')
    : qiankunWindow.document.querySelector('#app')
  app.mount(mountEl)
}

/** 销毁应用实例 */
const destroyApplication = () => {
  themeUnsub()
  themeUnsub = () => {}
  app?.unmount()
  app = null
  resetUser()
}

// 使用 vite-plugin-qiankun 的生命周期封装
renderWithQiankun({
  bootstrap() {
    console.log('[plugin] bootstrap')
  },
  mount(props) {
    return createApplication(props)
  },
  unmount() {
    destroyApplication()
  },
  update() {}
})

// 独立运行（开发调试模式：非 qiankun 环境时直接启动）
if (!qiankunWindow.__POWERED_BY_QIANKUN__) {
  createApplication()
}
