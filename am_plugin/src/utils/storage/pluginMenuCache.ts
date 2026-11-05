/**
 * 插件菜单缓存（stale-while-revalidate）
 *
 * 按插件标识（app_name）缓存后端返回的插件菜单树，用于「先渲染缓存、后台刷新」，
 * 避免每次打开插件都要等待菜单接口返回而拖慢首屏。
 *
 * - 缓存的是「已剥离 meta.appName、已过滤空项」但未规范化路径的原始菜单树，
 *   读取后仍会走 MenuProcessor 的规范化流程，保证与在线获取行为一致。
 * - 数据变更由每次加载时的后台刷新校正，这里不做硬过期丢弃。
 */
import type { AppRouteRecord } from '@/types/router'

const STORAGE_PREFIX = 'am_plugin_menu:'

interface PluginMenuCacheEntry {
  /** 写入时间戳 */
  t: number
  /** 插件标识，读写时校验一致，避免脏数据串号 */
  app: string
  /** 菜单树 */
  list: AppRouteRecord[]
}

const readCache = (appName: string): PluginMenuCacheEntry | null => {
  if (typeof window === 'undefined' || !appName) return null
  try {
    const raw = window.localStorage.getItem(STORAGE_PREFIX + appName)
    if (!raw) return null
    const entry = JSON.parse(raw) as PluginMenuCacheEntry
    if (!entry || entry.app !== appName || !Array.isArray(entry.list)) return null
    return entry
  } catch {
    return null
  }
}

/** 读取缓存的插件菜单树；无有效缓存返回 null */
export function getCachedPluginMenu(appName: string): AppRouteRecord[] | null {
  const entry = readCache(appName)
  return entry && entry.list.length > 0 ? entry.list : null
}

/** 写入插件菜单缓存（失败静默，如隐私模式/配额） */
export function setCachedPluginMenu(appName: string, list: AppRouteRecord[]): void {
  if (typeof window === 'undefined' || !appName || !Array.isArray(list) || list.length === 0) return
  try {
    const entry: PluginMenuCacheEntry = { t: Date.now(), app: appName, list }
    window.localStorage.setItem(STORAGE_PREFIX + appName, JSON.stringify(entry))
  } catch {
    /* ignore */
  }
}

/** 清除指定插件的菜单缓存 */
export function clearCachedPluginMenu(appName: string): void {
  if (typeof window === 'undefined' || !appName) return
  try {
    window.localStorage.removeItem(STORAGE_PREFIX + appName)
  } catch {
    /* ignore */
  }
}
