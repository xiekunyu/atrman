/**
 * 入口模式（双入口架构）
 *
 * 同一个构建产物会被两个后端页面入口加载：
 * - manage：管理入口 /app/{name}/manage → Art 后台路由树（菜单驱动，依赖基座免登）
 * - front ：前端入口 /app/{name}        → 公开前台路由树（无侧栏，不要求登录/基座/Bridge）
 *
 * front 板块代码一律不得假设 Bridge/token 存在，需要登录态的功能必须
 * 用 hasBridge() / userStore.isLogin 判断后做降级展示。
 *
 * 模式解析优先级（resolveEntryMode）：
 * 1. qiankun props.entryType —— 基座 views/plugin/app/index.vue 随 loadMicroApp 传入
 * 2. window.__PLUGIN_ENTRY__ —— post-build 生成的入口 HTML 内联注入（生产直连场景）
 * 3. 独立运行 hash 前缀 #/manage —— 本地开发预览后台（后台页面目录 views/manage）
 * 4. 其余独立运行场景（含直接访问根路径）默认前台，前台页面目录 views/index
 */

export type EntryMode = 'manage' | 'front'

declare global {
  interface Window {
    /** post-build 在入口 HTML 中注入的入口标识 */
    __PLUGIN_ENTRY__?: string
  }
}

let _mode: EntryMode = 'manage'

/** 由 main.ts 在挂载前调用（resolveEntryMode 的结果） */
export const setEntryMode = (mode: EntryMode) => {
  _mode = mode
}

/** 获取当前入口模式 */
export const getEntryMode = (): EntryMode => _mode

/** 是否运行在前台公开入口模式 */
export const isFrontEntry = (): boolean => _mode === 'front'

const isEntryMode = (value: unknown): value is EntryMode => value === 'front' || value === 'manage'

/** 基座注入的插件标识信息（qiankun props.plugin），供前台页跳转后台等场景使用 */
let _pluginMeta: { name: string; id: number | string } | null = null

/** 由 main.ts 在挂载时缓存基座传入的插件 meta */
export const setPluginMeta = (plugin?: Record<string, any>) => {
  if (plugin?.name && plugin?.id) {
    _pluginMeta = { name: String(plugin.name), id: plugin.id }
  }
}

/** 获取插件 meta（独立运行时为 null） */
export const getPluginMeta = () => _pluginMeta

/**
 * 解析当前应以哪种入口启动
 *
 * @param props qiankun mount 传入的 props（独立运行时为空）
 */
export const resolveEntryMode = (props: any = {}): EntryMode => {
  // 1) 基座 qiankun 显式传入（最可靠，覆盖 dev/prod）
  if (isEntryMode(props?.entryType)) return props.entryType

  // 2) 入口 HTML 内联注入的标识（生产环境直连访问）
  if (isEntryMode(window.__PLUGIN_ENTRY__)) return window.__PLUGIN_ENTRY__ as EntryMode

  // 3) 独立开发预览：hash 路由以 #/manage 开头视为后台管理入口（预览后台页）
  if (!props?.entryType && /^#\/manage(?:\/|\?|#|$)/.test(window.location.hash)) {
    return 'manage'
  }

  // 4) 独立运行默认前台公开入口：直接访问根路径及 #/index 等前台页均进前台
  return 'front'
}
