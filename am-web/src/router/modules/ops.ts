import { AppRouteRecord } from '@/types/router'

export const opsRoutes: AppRouteRecord = {
  path: '/ops',
  name: 'Ops',
  component: '/index/index',
  meta: {
    title: 'menus.ops.title',
    icon: 'ri:tools-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'log',
      name: 'SystemLog',
      component: '/ops/log',
      meta: {
        title: 'menus.ops.log',
        icon: 'ri:file-list-3-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    },
    {
      path: 'loginLog',
      name: 'LoginLog',
      component: '/ops/loginLog',
      meta: {
        title: 'menus.ops.loginLog',
        icon: 'ri:login-box-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    },
    {
      path: 'cache',
      name: 'SystemCache',
      component: '/ops/cache',
      meta: {
        title: 'menus.ops.cache',
        icon: 'ri:database-2-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    },
    {
      path: 'server',
      name: 'SystemServer',
      component: '/ops/server',
      meta: {
        title: 'menus.ops.server',
        icon: 'ri:server-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    },
    {
      path: 'backup',
      name: 'SystemBackup',
      component: '/ops/backup',
      meta: {
        title: 'menus.ops.backup',
        icon: 'ri:hard-drive-3-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    },
    {
      path: 'info',
      name: 'SystemInfo',
      component: '/ops/info',
      meta: {
        title: 'menus.ops.info',
        icon: 'ri:information-2-line',
        keepAlive: true,
        roles: ['R_SUPER']
      }
    }
  ]
}
