/** 菜单状态管理：菜单列表、首页路径、动态路由注册与清理 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { AppRouteRecord } from '@/types/router'
import { getFirstMenuPath } from '@/utils'
import { HOME_PAGE_PATH } from '@/router'

export const useMenuStore = defineStore('menuStore', () => {
  /** 首页路径 */
  const homePath = ref(HOME_PAGE_PATH)
  /** 菜单列表 */
  const menuList = ref<AppRouteRecord[]>([])
  /** 菜单宽度 */
  const menuWidth = ref('')
  /** 存储路由移除函数的数组 */
  const removeRouteFns = ref<(() => void)[]>([])

  const setMenuList = (list: AppRouteRecord[]) => {
    menuList.value = list
    setHomePath(HOME_PAGE_PATH || getFirstMenuPath(list))
  }

  const getHomePath = () => homePath.value

  const setHomePath = (path: string) => {
    homePath.value = path
  }

  const addRemoveRouteFns = (fns: (() => void)[]) => {
    removeRouteFns.value.push(...fns)
  }

  const removeAllDynamicRoutes = () => {
    removeRouteFns.value.forEach((fn) => fn())
    removeRouteFns.value = []
  }

  const clearRemoveRouteFns = () => {
    removeRouteFns.value = []
  }

  return {
    menuList,
    menuWidth,
    removeRouteFns,
    setMenuList,
    getHomePath,
    setHomePath,
    addRemoveRouteFns,
    removeAllDynamicRoutes,
    clearRemoveRouteFns
  }
})
