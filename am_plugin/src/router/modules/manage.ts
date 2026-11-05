import { AppRouteRecord } from '@/types/router'

/**
 * 后台管理路由模块（manage 板块）
 *
 * 插件开发者在此配置自己的后台管理页面路由。
 * component 路径相对于 src/views/ 目录。
 * 布局容器使用 /manage/index（views/manage/index.vue），与 am-web 一级路由写法一致。
 */
export const manageRoutes: AppRouteRecord = {
  path: '/manage',
  name: 'ManageRoot',
  component: '/manage/index', // 布局容器（左右结构）
  redirect: '/manage/home',
  meta: {
    title: '插件',
    icon: 'ri:apps-line'
  },
  children: [
    {
      path: 'home',
      name: 'Home',
      component: '/manage/home/index',
      meta: {
        title: '首页',
        icon: 'ri:home-4-line',
        keepAlive: true
      }
    },
    {
      path: 'crud',
      name: 'Crud',
      component: '/manage/crud/index',
      meta: {
        title: 'CRUD 示例',
        icon: 'ri:list-check',
        keepAlive: true
      }
    },
    {
      path: 'example',
      name: 'PluginApiExample',
      component: '/example/index',
      meta: {
        title: '接口测试示例',
        icon: 'ri:flask-line',
        keepAlive: true
      }
    }
  ]
}
