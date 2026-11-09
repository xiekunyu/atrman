/**
 * 插件微应用（qiankun）加载辅助：统一入口解析、详情缓存穿透、静态资源预取
 *
 * 目标：解决基座按需 loadMicroApp 首次打开子应用慢的问题——
 *  - 详情缓存穿透：同一次会话内重复打开同一插件不再阻塞式重新请求详情
 *  - 资源预取：在门户页空闲时预下载子应用入口 HTML/JS/CSS，命中浏览器 HTTP 缓存，
 *    真正打开时无需等待整包下载
 *  - 入口解析集中化：app 页加载与门户页预取复用同一套解析逻辑，避免版本参数/iframe 判定漂移
 */
import { prefetchApps } from 'qiankun'
import { getApiBaseURL } from '@/utils/env'
import { parseManifest, getPluginAssetVersion } from '@/utils/plugin'
import pluginApi from '@/api/plugin/app'

type PluginRecord = Record<string, any>

export interface ResolvedMicroEntry {
  /** 解析后的 manifest 对象 */
  manifest: Record<string, any>
  /** 原始入口（可能为相对路径，空字符串表示未配置） */
  entry: string
  /** 补全协议域名后的绝对入口地址 */
  absoluteEntry: string
  /** 附带资源版本参数的入口地址（与 loadMicroApp 使用的 entry 完全一致，确保缓存命中） */
  versionedEntryUrl: string
  /** 是否为 iframe 打开模式 */
  isIframe: boolean
}

/** 解析插件微应用入口（供 app 页加载与门户页预取复用） */
export const resolveMicroEntry = (
  detail: PluginRecord,
  entryType: 'app' | 'manage' = 'manage'
): ResolvedMicroEntry => {
  const manifest = parseManifest(detail.manifest)
  const entry = String(
    entryType === 'app'
      ? detail.entry || manifest.entry || ''
      : detail.manage_entry || manifest.manage_entry || detail.entry || manifest.entry || ''
  )
  const absoluteEntry = /^https?:\/\//i.test(entry) ? entry : getApiBaseURL() + entry
  const version = getPluginAssetVersion(detail)
  const versionedEntryUrl = version
    ? `${absoluteEntry}${absoluteEntry.includes('?') ? '&' : '?'}qiankun=${encodeURIComponent(version)}`
    : absoluteEntry
  const openMode = String(detail.open_mode || manifest.open_mode || 'micro')
  return { manifest, entry, absoluteEntry, versionedEntryUrl, isIframe: openMode === 'iframe' }
}

// ─────────────────────── 插件详情缓存（会话级） ───────────────────────
const detailCache = new Map<string, PluginRecord>()

export const getCachedPluginDetail = (name: string): PluginRecord | null =>
  detailCache.get(name) || null

export const cachePluginDetail = (name: string, detail: PluginRecord) => {
  detailCache.set(name, detail)
}

/** 失效插件详情：安装/启用/停用/重载/更新/卸载后调用，避免读到过期入口/状态 */
export const invalidatePluginDetail = (name?: string) => {
  if (name) detailCache.delete(name)
  else detailCache.clear()
}

/** 缓存穿透读取插件详情：命中缓存即时返回，否则请求并回填 */
export const loadPluginDetail = async (name: string): Promise<PluginRecord> => {
  const cached = detailCache.get(name)
  if (cached) return cached
  const detail = await pluginApi.readByName(name)
  detailCache.set(name, detail)
  return detail
}

// ─────────────────────── 静态资源预取 ───────────────────────
// 记录已预取/已加载的应用名，避免重复发起预取请求
const prefetchedNames = new Set<string>()

/** 标记应用已加载（app 页挂载后调用），后续预取跳过它 */
export const markPluginLoaded = (name: string) => {
  prefetchedNames.add(name)
}

/** 若插件状态/入口变更需要重新预取，清除其预取标记 */
export const unmarkPluginPrefetched = (name: string) => {
  prefetchedNames.delete(name)
}

/**
 * 预取一批插件的微应用资源（仅处理启用、非移动端、非 iframe 且有入口的应用）。
 * prefetchApps 会下载并缓存入口 HTML 及其脚本/样式，命中浏览器 HTTP 缓存，
 * 真正打开时几乎无需等待整包下载。整个过程静默执行，失败不影响页面。
 */
export const prefetchPluginApps = (plugins: PluginRecord[]) => {
  const apps: { name: string; entry: string }[] = []
  plugins.forEach((p) => {
    if (!p) return
    if (Number(p.status) !== 1) return
    if (Number(p.platform || 1) === 3) return
    const name = String(p.name || '')
    if (!name || prefetchedNames.has(name)) return
    const resolved = resolveMicroEntry(p, 'manage')
    if (!resolved.entry || resolved.isIframe) return
    // 本地回环地址（localhost / 127.0.0.1，如本地 dev server）是开发调试入口：
    // 预取没有网络收益，且 dev server 未启动时 prefetchApps 会触发
    // ERR_CONNECTION_REFUSED + Failed to fetch 的控制台噪音。跳过预取，
    // 实际打开插件仍由 loadMicroApp 正常加载，不受影响。
    if (/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/i.test(resolved.absoluteEntry)) return
    prefetchedNames.add(name)
    apps.push({ name, entry: resolved.versionedEntryUrl })
  })
  if (!apps.length) return
  try {
    prefetchApps(apps)
  } catch (error) {
    console.warn('[pluginMicroApp] 预取子应用资源失败:', error)
  }
}

/** 空闲调度预取：不阻塞主线程，浏览器空闲时再下载 */
export const prefetchPluginAppsIdle = (plugins: PluginRecord[]) => {
  const run = () => prefetchPluginApps(plugins)
  if (typeof window === 'undefined') return
  const ric = (window as any).requestIdleCallback
  if (typeof ric === 'function') ric(run, { timeout: 3000 })
  else setTimeout(run, 800)
}
