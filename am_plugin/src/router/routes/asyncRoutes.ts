// 权限文档：https://www.artd.pro/docs/zh/guide/in-depth/permission.html
import { AppRouteRecord } from '@/types/router'
import { manageRoutes } from '../modules/manage'

/**
 * 动态路由（需要权限才能访问的路由）
 * 用于渲染菜单以及根据菜单权限动态加载路由，如果没有权限无法访问
 *
 * 后台管理（manage 板块）默认菜单：插件开发者在 modules/manage.ts 中维护。
 */
export const asyncRoutes: AppRouteRecord[] = [manageRoutes]
