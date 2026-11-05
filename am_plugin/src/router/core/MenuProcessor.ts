/**
 * 菜单处理器
 *
 * 负责菜单数据的获取、过滤和处理
 *
 * @module router/core/MenuProcessor
 * @author Art Design Pro Team
 */

import type { AppRouteRecord } from '@/types/router'
import { useUserStore } from '@/store/modules/user'
import { useAppMode } from '@/hooks/core/useAppMode'
import { fetchGetPluginMenuList } from '@/api/system-manage'
import { asyncRoutes } from '../routes/asyncRoutes'
import { RoutesAlias } from '../routesAlias'
import { formatMenuTitle } from '@/utils'
import { getPluginName } from '@/utils/env'
import { getCachedPluginMenu, setCachedPluginMenu, clearCachedPluginMenu } from '@/utils/storage/pluginMenuCache'

export class MenuProcessor {
  /**
   * 后台刷新拿到最新菜单后的回调（由 initRouter 注入：增量注册新路由 + 更新 menuStore 侧栏）
   * 入参为「已规范化」的最新菜单树
   */
  onFresh?: (menuList: AppRouteRecord[]) => void

  /**
   * 获取菜单数据
   */
  async getMenuList(): Promise<AppRouteRecord[]> {
    const { isFrontendMode } = useAppMode()

    let menuList: AppRouteRecord[]
    if (isFrontendMode.value) {
      menuList = await this.processFrontendMenu()
    } else {
      menuList = await this.processBackendMenu()
    }

    // 在规范化路径之前，验证原始路径配置
    this.validateMenuPaths(menuList)

    // 规范化路径（将相对路径转换为完整路径）
    return this.normalizeMenuPaths(menuList)
  }

  /**
   * 处理前端控制模式的菜单
   */
  private async processFrontendMenu(): Promise<AppRouteRecord[]> {
    const userStore = useUserStore()
    const roles = userStore.info?.roles

    let menuList = [...asyncRoutes]

    // 根据角色过滤菜单
    if (roles && roles.length > 0) {
      menuList = this.filterMenuByRoles(menuList, roles)
    }

    return this.filterEmptyMenus(menuList)
  }

  /**
   * 处理后端控制模式的菜单
   * 与基座唯一不同处：插件用「基座 bridge 传入的插件标识」调插件菜单接口，并做本地缓存 SWR：
   * 命中缓存先即时返回（不阻塞首屏），后台拉最新→有变化则写缓存并回调 onFresh 更新路由/侧栏。
   * 无标识/未登录时回退前端默认菜单；接口正常返回空菜单则如实呈现空侧栏。
   */
  private async processBackendMenu(): Promise<AppRouteRecord[]> {
    const appName = this.resolveAppName()
    if (!appName) return this.processFrontendMenu()

    const cached = getCachedPluginMenu(appName)
    const { accessToken } = useUserStore()

    // 无 token（如独立预览未登录）：不发请求避免 401；有缓存先渲染缓存，否则回退默认菜单
    if (!accessToken) {
      return cached ? cached : this.processFrontendMenu()
    }

    // 命中缓存：先即时返回渲染，并触发后台刷新（不 await）
    if (cached) {
      this.revalidate(appName, cached)
      return cached
    }

    // 无缓存：首次阻塞拉取
    const fresh = await this.fetchPluginMenu(appName)
    // 请求失败（null）：不动缓存，回退默认菜单
    if (fresh === null) return this.processFrontendMenu()
    // 有菜单：写缓存并返回
    if (fresh.length) {
      setCachedPluginMenu(appName, fresh)
      return fresh
    }
    clearCachedPluginMenu(appName)
    // 生产不回退前端菜单（静态路由无角色过滤，回退=越权显示）；dev 联调兜底，避免空菜单落 404
    if (import.meta.env.DEV) return this.processFrontendMenu()
    return []
  }

  /**
   * 拉取并整理插件菜单：调接口 → 过滤空项 → 剥离 meta.appName。
   * 返回 [] 表示「接口成功但无菜单」（应清缓存、如实呈现空侧栏）；
   * 返回 null 表示「请求失败」（应保留缓存与当前 UI，下次再校正）。
   */
  private async fetchPluginMenu(appName: string): Promise<AppRouteRecord[] | null> {
    try {
      const list = (await fetchGetPluginMenuList(appName)) || []
      return this.stripAppName(this.filterEmptyMenus(list))
    } catch (e) {
      console.warn('[plugin] 拉取插件菜单失败', e)
      return null
    }
  }

  /**
   * 后台刷新：拉最新菜单校正缓存与侧栏。
   * - 请求失败(null)：保留缓存与当前 UI，下次再校正
   * - 接口成功但为空([])：只清缓存，不用前端静态菜单顶上去（静态路由无角色过滤，回退=越权显示）
   * - 有菜单：写缓存；与当前不同则规范化后回调 onFresh 更新路由/侧栏
   */
  private revalidate(appName: string, served: AppRouteRecord[]): void {
    this.fetchPluginMenu(appName)
      .then((fresh) => {
        if (fresh === null) return
        if (!fresh.length) {
          clearCachedPluginMenu(appName)
          return
        }
        setCachedPluginMenu(appName, fresh)
        if (JSON.stringify(fresh) === JSON.stringify(served)) return
        this.validateMenuPaths(fresh)
        this.onFresh?.(this.normalizeMenuPaths(fresh))
      })
      .catch(() => {
        /* 后台刷新异常忽略，下次加载再校正 */
      })
  }

  /**
   * 解析当前插件标识：统一从构建期环境变量 VITE_PLUGIN_NAME 读取。
   * （插件内几乎所有接口都会用到该标识，故不依赖基座 bridge 注入，保证来源唯一、编译期确定）
   */
  private resolveAppName(): string {
    return getPluginName()
  }

  /**
   * 递归剥离后端插件菜单的 meta.appName（createArtdMenus 会写入插件名），
   * 否则 RouteRegistry.register 会跳过 meta.appName 非空的项导致全部不注册
   */
  private stripAppName(list: AppRouteRecord[]): AppRouteRecord[] {
    return list.map((item) => {
      const { appName: _drop, ...meta } = item.meta || {}
      const children = item.children?.length ? this.stripAppName(item.children) : item.children
      return { ...item, meta, ...(children ? { children } : {}) }
    })
  }

  /**
   * 根据角色过滤菜单
   */
  private filterMenuByRoles(menu: AppRouteRecord[], roles: string[]): AppRouteRecord[] {
    return menu.reduce((acc: AppRouteRecord[], item) => {
      const itemRoles = item.meta?.roles
      const hasPermission = !itemRoles || itemRoles.some((role) => roles?.includes(role))

      if (hasPermission) {
        const filteredItem = { ...item }
        if (filteredItem.children?.length) {
          filteredItem.children = this.filterMenuByRoles(filteredItem.children, roles)
        }
        acc.push(filteredItem)
      }

      return acc
    }, [])
  }

  /**
   * 递归过滤空菜单项
   */
  private filterEmptyMenus(menuList: AppRouteRecord[]): AppRouteRecord[] {
    return menuList
      .map((item) => {
        // 如果有子菜单，先递归过滤子菜单
        if (item.children && item.children.length > 0) {
          const filteredChildren = this.filterEmptyMenus(item.children)
          return {
            ...item,
            children: filteredChildren
          }
        }
        return item
      })
      .filter((item) => {
        // 如果定义了 children 属性（即使是空数组），说明这是一个目录菜单，应该保留
        if ('children' in item) {
          return true
        }

        // 如果有外链或 iframe，保留
        if (item.meta?.isIframe === true || item.meta?.link) {
          return true
        }

        // 如果有有效的 component，保留
        if (item.component && item.component !== '' && item.component !== RoutesAlias.Layout) {
          return true
        }

        // 后端插件菜单只要有可导航的 path（页面/目录，component 可能留空或为 Layout）也应保留，
        // 否则会被整体过滤空导致回退默认静态菜单
        if (item.path && item.path !== '/') {
          return true
        }

        // 其他情况过滤掉
        return false
      })
  }

  /**
   * 验证菜单列表是否有效
   */
  validateMenuList(menuList: AppRouteRecord[]): boolean {
    return Array.isArray(menuList) && menuList.length > 0
  }

  /**
   * 规范化菜单路径
   * 将相对路径转换为完整路径，确保菜单跳转正确
   */
  private normalizeMenuPaths(menuList: AppRouteRecord[], parentPath = ''): AppRouteRecord[] {
    return menuList.map((item) => {
      // 构建完整路径
      const fullPath = this.buildFullPath(item.path || '', parentPath)

      // 递归处理子菜单
      const children = item.children?.length
        ? this.normalizeMenuPaths(item.children, fullPath)
        : item.children

      const redirect = item.redirect || this.resolveDefaultRedirect(children)

      return {
        ...item,
        path: fullPath,
        redirect,
        children
      }
    })
  }

  /**
   * 为目录型菜单推导默认跳转地址
   */
  private resolveDefaultRedirect(children?: AppRouteRecord[]): string | undefined {
    if (!children?.length) {
      return undefined
    }

    for (const child of children) {
      if (this.isNavigableRoute(child)) {
        return child.path
      }

      const nestedRedirect = this.resolveDefaultRedirect(child.children)
      if (nestedRedirect) {
        return nestedRedirect
      }
    }

    return undefined
  }

  /**
   * 判断子路由是否可以作为默认落点
   */
  private isNavigableRoute(route: AppRouteRecord): boolean {
    return Boolean(
      route.path &&
        route.path !== '/' &&
        !route.meta?.link &&
        route.meta?.isIframe !== true &&
        route.component &&
        route.component !== ''
    )
  }

  /**
   * 验证菜单路径配置
   * 检测非一级菜单是否错误使用了 / 开头的路径
   */
  /**
   * 验证菜单路径配置
   * 检测非一级菜单是否错误使用了 / 开头的路径
   */
  private validateMenuPaths(menuList: AppRouteRecord[], level = 1): void {
    menuList.forEach((route) => {
      if (!route.children?.length) return

      const parentName = String(route.name || route.path || '未知路由')

      route.children.forEach((child) => {
        const childPath = child.path || ''

        // 跳过合法的绝对路径：外部链接和 iframe 路由
        if (this.isValidAbsolutePath(childPath)) return

        // 检测非法的绝对路径
        if (childPath.startsWith('/')) {
          this.logPathError(child, childPath, parentName, level)
        }
      })

      // 递归检查更深层级的子路由
      this.validateMenuPaths(route.children, level + 1)
    })
  }

  /**
   * 判断是否为合法的绝对路径
   */
  private isValidAbsolutePath(path: string): boolean {
    return (
      path.startsWith('http://') ||
      path.startsWith('https://') ||
      path.startsWith('/outside/iframe/')
    )
  }

  /**
   * 输出路径配置错误日志
   */
  private logPathError(
    route: AppRouteRecord,
    path: string,
    parentName: string,
    level: number
  ): void {
    const routeName = String(route.name || path || '未知路由')
    const menuTitle = route.meta?.title || routeName
    const suggestedPath = path.split('/').pop() || path.slice(1)

    console.error(
      `[路由配置错误] 菜单 "${formatMenuTitle(menuTitle)}" (name: ${routeName}, path: ${path}) 配置错误\n` +
        `  位置: ${parentName} > ${routeName}\n` +
        `  问题: ${level + 1}级菜单的 path 不能以 / 开头\n` +
        `  当前配置: path: '${path}'\n` +
        `  应该改为: path: '${suggestedPath}'`
    )
  }

  /**
   * 构建完整路径
   */
  private buildFullPath(path: string, parentPath: string): string {
    if (!path) return ''

    // 外部链接直接返回
    if (path.startsWith('http://') || path.startsWith('https://')) {
      return path
    }

    // 如果已经是绝对路径，直接返回
    if (path.startsWith('/')) {
      return path
    }

    // 拼接父路径和当前路径
    if (parentPath) {
      // 移除父路径末尾的斜杠，移除子路径开头的斜杠，然后拼接
      const cleanParent = parentPath.replace(/\/$/, '')
      const cleanChild = path.replace(/^\//, '')
      return `${cleanParent}/${cleanChild}`
    }

    // 没有父路径，添加前导斜杠
    return `/${path}`
  }
}
