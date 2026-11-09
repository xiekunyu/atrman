import type { AppRouteRecordRaw } from '@/utils/router'

/**
 * 前台公开入口路由（双入口架构之前台 index 板块）
 *
 * - 挂载于 /index 路径段，静态注册（不依赖菜单接口），任何形态都可访问：
 *   直连公开访问、基座 qiankun 内打开、本地独立预览（#/index/...）。
 * - 前台页面禁止依赖 menu store / worktab / 菜单守卫等后台强绑定模块。
 * - 需要登录态的功能必须用 hasBridge() / userStore.isLogin 判断后做降级展示。
 * - 对应入口视图 index.html（/app/{name}），入口模式 token 仍为 'front'（与基座协议一致）。
 */
export const INDEX_HOME_PATH = '/index/home'

export const indexRoutes: AppRouteRecordRaw = {
  path: '/index',
  name: 'IndexLayout',
  component: () => import('@/views/index/layout/index.vue'),
  redirect: INDEX_HOME_PATH,
  meta: {
    title: '插件前台',
    isHide: true,
    requiresAuth: false
  },
  children: [
    {
      path: 'home',
      name: 'IndexHome',
      component: () => import('@/views/index/home/index.vue'),
      meta: {
        title: '前台首页',
        isHide: true,
        requiresAuth: false
      }
    },
    {
      path: 'detail/:id',
      name: 'IndexDetail',
      component: () => import('@/views/index/detail/index.vue'),
      meta: {
        title: '内容详情',
        isHide: true,
        requiresAuth: false
      }
    }
  ]
}
