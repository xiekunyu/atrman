import { AppRouteRecordRaw } from '@/utils/router'
import { indexRoutes } from '../modules/index'

/**
 * 静态路由配置（不需要权限就能访问的路由）
 *
 * 错误页面复用 am-web 的 ArtException 组件 + SVG 插图
 * indexRoutes：前台公开入口路由树（/index/**，双入口架构，不依赖菜单接口）
 */
export const staticRoutes: AppRouteRecordRaw[] = [
  indexRoutes,
  {
    path: '/403',
    name: 'Exception403',
    component: () => import('@/views/exception/403/index.vue'),
    meta: { title: '403', isHideTab: true }
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'Exception404',
    component: () => import('@/views/exception/404/index.vue'),
    meta: { title: '404', isHideTab: true }
  },
  {
    path: '/500',
    name: 'Exception500',
    component: () => import('@/views/exception/500/index.vue'),
    meta: { title: '500', isHideTab: true }
  }
]
