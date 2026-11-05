export type PluginRecord = Record<string, any>
export type RequirementTag = { text: string; type: 'danger' | 'warning' }
export type PluginStatusType = 'success' | 'info' | 'warning' | 'danger'

const iconPalette = ['#24c263', '#ffad0d', '#4d8df7', '#ff7d7d', '#9ac7ff', '#ffcf74']
const iconList = [
  'ri:apps-2-line',
  'ri:rocket-2-line',
  'ri:todo-line',
  'ri:calendar-check-line',
  'ri:notification-3-line',
  'ri:briefcase-4-line'
]

export const parseManifest = (manifest: unknown): Record<string, any> => {
  if (!manifest) return {}
  if (typeof manifest === 'string') {
    try {
      const parsed = JSON.parse(manifest)
      return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
      return {}
    }
  }
  return typeof manifest === 'object' ? (manifest as Record<string, any>) : {}
}

export const isImageIcon = (icon: string) =>
  /^(https?:)?\/\//i.test(icon) ||
  icon.startsWith('/') ||
  /^(data:image\/|blob:)/i.test(icon) ||
  /\.(png|jpe?g|gif|webp|svg|ico)(\?.*)?$/i.test(icon)

export const getStatusText = (status: number | string) => {
  const statusMap: Record<number, string> = {
    [-1]: '异常',
    0: '已禁用',
    1: '已启用',
    2: '未安装'
  }
  return statusMap[Number(status)] || '未知'
}

export const getStatusType = (status: number | string): PluginStatusType => {
  const typeMap: Record<number, PluginStatusType> = {
    [-1]: 'danger',
    0: 'info',
    1: 'success',
    2: 'warning'
  }
  return typeMap[Number(status)] || 'info'
}

export const isCustomPlugin = (plugin: PluginRecord) => Number(plugin.is_custom || 0) === 1

export const isDevPlugin = (plugin: PluginRecord) => Number(plugin.is_dev || 0) === 1

export const getPluginIndex = (plugin: PluginRecord) =>
  Math.abs(
    String(plugin.name || plugin.id || '')
      .split('')
      .reduce((sum, char) => sum + char.charCodeAt(0), 0)
  )

export const getPluginIconKey = (plugin: PluginRecord) => String(plugin.id || plugin.name || '')

export const getFallbackIcon = (plugin: PluginRecord) => iconList[getPluginIndex(plugin) % iconList.length]

export const getPluginAssetVersion = (plugin: PluginRecord) => String(plugin.update_time || '')

const withStableAssetVersion = (url: string, plugin: PluginRecord) => {
  if (!isImageIcon(url) || /^(data:image\/|blob:)/i.test(url) || !isCustomPlugin(plugin)) {
    return url
  }
  const version = getPluginAssetVersion(plugin)
  if (!version) return url
  return `${url}${url.includes('?') ? '&' : '?'}v=${encodeURIComponent(version)}`
}

export const getRawPluginIcon = (plugin: PluginRecord, failedKeys?: Set<string>) => {
  const manifest = parseManifest(plugin.manifest)
  if (failedKeys?.has(getPluginIconKey(plugin))) {
    return getFallbackIcon(plugin)
  }
  return plugin.icon || manifest.icon || getFallbackIcon(plugin)
}

export const getPluginIcon = (plugin: PluginRecord, failedKeys?: Set<string>) => {
  const icon = getRawPluginIcon(plugin, failedKeys)
  return withStableAssetVersion(icon, plugin)
}

export const isImagePluginIcon = (plugin: PluginRecord, failedKeys?: Set<string>) =>
  isImageIcon(getRawPluginIcon(plugin, failedKeys))

export const getPluginIconStyle = (plugin: PluginRecord, failedKeys?: Set<string>) => {
  if (isImagePluginIcon(plugin, failedKeys)) {
    return { backgroundColor: 'var(--el-fill-color-light)' }
  }

  const manifest = parseManifest(plugin.manifest)
  const color = manifest.color || iconPalette[getPluginIndex(plugin) % iconPalette.length]
  return { backgroundColor: color }
}

export const hasUnsatisfiedDependencies = (plugin: PluginRecord) =>
  Array.isArray(plugin.dependency_info) &&
  plugin.dependency_info.some((dependency: Record<string, any>) => !dependency.satisfied)

export const canEnablePlugin = (plugin: PluginRecord) =>
  plugin.compatibility_info?.compatible !== false && !hasUnsatisfiedDependencies(plugin)

export const getRequirementMessage = (plugin: PluginRecord) => {
  if (plugin.compatibility_info?.compatible === false) {
    return plugin.compatibility_info.message || '插件不兼容当前系统版本'
  }
  const dependency = Array.isArray(plugin.dependency_info)
    ? plugin.dependency_info.find((item: Record<string, any>) => !item.satisfied)
    : null
  return dependency?.message || '插件依赖未满足'
}

export const getRequirementTags = (plugin: PluginRecord) => {
  const tags: RequirementTag[] = []
  if (plugin.compatibility_info?.compatible === false) {
    tags.push({ text: '不兼容', type: 'danger' })
  }
  if (hasUnsatisfiedDependencies(plugin)) {
    tags.push({ text: '依赖异常', type: 'warning' })
  }
  return tags
}

export const resolvePluginEntry = (plugin: PluginRecord, type: 'app' | 'manage' = 'manage') => {
  const manifest = parseManifest(plugin.manifest)
  if (type === 'app') {
    return String(plugin.entry || manifest.entry || '')
  }
  return String(plugin.manage_entry || manifest.manage_entry || plugin.entry || manifest.entry || '')
}

export const hasPluginEntry = (plugin: PluginRecord, type: 'app' | 'manage' = 'manage') =>
  Boolean(resolvePluginEntry(plugin, type))
