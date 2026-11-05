import type { App } from 'vue'
import { createRouter, createWebHashHistory, createMemoryHistory, type Router } from 'vue-router'
import type { AppRouteRecord } from '@/types/router'
import { qiankunWindow } from 'vite-plugin-qiankun/dist/helper'
import { staticRoutes } from './routes/staticRoutes'
import { configureNProgress } from '@/utils/router'
import { setupBeforeEachGuard, resetRouteInitState } from './guards/beforeEach'
import { setupAfterEachGuard } from './guards/afterEach'
import { RouteRegistry, MenuProcessor } from './core'
import { useMenuStore } from '@/store/modules/menu'
import { isFrontEntry } from '@/sdk/entry'

// 创建路由实例工厂
// 关键：qiankun 基座环境下使用「内存路由」，子应用路由状态完全独立，
// 不与基座共享 window.location.hash，避免被基座地址（如 #/plugin-app/...）
// 带偏而匹配到无布局的兜底路由（导致左侧菜单不显示）。
// 独立运行（开发预览）时仍使用 hash 路由，方便直接访问各页面。
function createAppRouter(): Router {
  return createRouter({
    history: qiankunWindow.__POWERED_BY_QIANKUN__ ? createMemoryHistory() : createWebHashHistory(),
    routes: staticRoutes // 静态路由
  })
}

// 模块级路由实例。
// 双入口（front/manage）共用同一份构建产物，浏览器按 URL 全局缓存 ES 模块，
// 模块级 router 不会随子应用卸载而重置：若先以前台入口初始化、再以管理入口挂载，
// 会复用被前台导航污染的旧实例（内存历史停在 /index、守卫重复叠加），
// 导致后台入口永远显示前台。故每次挂载都在 initRouter 中重建全新实例以彻底隔离。
export let router: Router = createAppRouter()

// 菜单处理器（与基座同一套链路的入口）
const menuProcessor = new MenuProcessor()

// 初始化路由（复用基座菜单机制：MenuProcessor.getMenuList → RouteRegistry.register → menuStore.setMenuList）
export async function initRouter(app: App<Element>): Promise<void> {
  // 重建全新路由实例并重置守卫初始化状态：双入口隔离的关键（见上方 createAppRouter 说明）。
  // 必须在任何入口分支/动态注册之前换新实例，后续引用（含 applyMenu 闭包、守卫注册）都会读取到最新 router。
  router = createAppRouter()
  resetRouteInitState()

  configureNProgress() // 顶部进度条

  // 前台公开入口（双入口架构）：不拉菜单、不注册菜单驱动路由，
  // 前台路由树已在 staticRoutes 静态注册（根路径由守卫重定向到 /index/home）
  if (isFrontEntry()) {
    setupBeforeEachGuard(router)
    setupAfterEachGuard(router)
    app.use(router)
    return
  }

  const menuStore = useMenuStore()

  // 应用一份菜单数据：先渲染侧栏，再注册路由。侧栏渲染不应被路由注册异常阻断
  // （否则单条路由校验失败会 throw，导致 setMenuList 不执行，菜单停在默认静态）
  const applyMenu = (menuList: AppRouteRecord[]) => {
    if (!menuProcessor.validateMenuList(menuList)) return
    // 先更新侧栏菜单（拿到数据即渲染）
    menuStore.setMenuList(menuList)
    try {
      // 深克隆，防止 RouteTransformer 原地修改导致菜单数据损坏
      const registry = new RouteRegistry(router)
      registry.register(JSON.parse(JSON.stringify(menuList)))
      menuStore.addRemoveRouteFns(registry.getRemoveRouteFns())
    } catch (e) {
      console.warn('[plugin] 动态路由注册失败（菜单已更新，部分路由可能缺失）', e)
    }
  }

  // 后台刷新拿到更新后的菜单：增量注册新路由 + 刷新侧栏（缓存命中时首屏不阻塞网络）
  menuProcessor.onFresh = (fresh) => {
    try {
      applyMenu(fresh)
    } catch (e) {
      console.warn('[plugin] 后台刷新菜单失败', e)
    }
  }

  // 获取菜单（命中缓存即时返回；无标识/未登录/无菜单时回退默认前端菜单）
  const menuList = await menuProcessor.getMenuList()
  applyMenu(menuList)

  setupBeforeEachGuard(router) // 路由前置守卫
  setupAfterEachGuard(router) // 路由后置守卫
  app.use(router)
}

// 主页路径，默认使用菜单第一个有效路径（与基座一致置空，回退由 setMenuList 计算）
export const HOME_PAGE_PATH = ''
