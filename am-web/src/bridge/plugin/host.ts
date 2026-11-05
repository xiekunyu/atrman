import { authBridgeService } from './services/auth'
import { dataBridgeService } from './services/data'
import { createNavigationBridgeService } from './services/navigation'
import { uiBridgeService } from './services/ui'
import { SCOPE_METHODS } from './scopes'
import type { BridgeMethod, PluginBridgeContext, PluginBridgeHost, PluginThemeState } from './types'

const VERSION = '1.0.0'

const DEFAULT_CAPABILITIES = [
  'auth.getLoginState',
  'auth.getUserProfile',
  'auth.getAccessToken',
  'data.getDict',
  'navigation.setTitle',
  'ui.message',
  'theme.get'
]

const ALL_CAPABILITIES = [
  ...DEFAULT_CAPABILITIES,
  'auth.getSession',
  'auth.logout',
  'data.getConfig',
  'data.getRemoteOptions',
  'picker.structure',
  'picker.file',
  'form.builder',
  'form.render',
  'navigation.openPlugin',
  'navigation.openRoute',
  'ui.notify',
  'ui.confirm',
  'ui.alert'
]

const getBridgeConfig = (context: PluginBridgeContext) => {
  return context.manifest?.bridge || context.plugin?.config?.bridge || {}
}

const getEntryOrigin = (entryUrl: string) => {
  try {
    return new URL(entryUrl, window.location.origin).origin
  } catch {
    return ''
  }
}

const getCapabilities = (context: PluginBridgeContext) => {
  if (import.meta.env.DEV) return ALL_CAPABILITIES

  const config = getBridgeConfig(context)
  const scopes = Array.isArray(config.scopes) ? config.scopes : []

  if (!scopes.length) return DEFAULT_CAPABILITIES

  return Array.from(
    new Set([
      ...DEFAULT_CAPABILITIES,
      ...scopes.flatMap((scope: string) => SCOPE_METHODS[scope] || [])
    ])
  )
}

const canUseBridge = (context: PluginBridgeContext) => {
  if (import.meta.env.DEV) {
    console.info('[PluginBridge] dev mode: skip same-origin check')
    return true
  }

  const config = getBridgeConfig(context)
  const isSameOrigin = getEntryOrigin(context.entryUrl) === window.location.origin
  return isSameOrigin || config.trust === 'same-origin'
}

export const createPluginBridgeHost = (context: PluginBridgeContext): PluginBridgeHost => {
  const available = canUseBridge(context)
  const navigationBridgeService = createNavigationBridgeService(context)
  const capabilities = available ? getCapabilities(context) : []
  let destroyed = false

  // 主题订阅：监听基座主题变化，广播给已订阅的子应用（菜单布局不同步）
  const themeListeners = new Set<(theme: PluginThemeState) => void>()
  let stopThemeWatch: (() => void) | null = null
  if (available && context.watchTheme) {
    stopThemeWatch = context.watchTheme((theme) => {
      themeListeners.forEach((cb) => cb(theme))
    })
  }

  const assertUsable = (method: string) => {
    if (destroyed) {
      throw new Error('PluginBridge 已销毁')
    }
    if (!available) {
      throw new Error('BRIDGE_UNAVAILABLE')
    }
    if (!capabilities.includes(method)) {
      throw new Error(`插件未开通能力：${method}`)
    }
  }

  const invoke = async <T = any>(method: BridgeMethod | string, params?: any): Promise<T> => {
    assertUsable(method)

    switch (method) {
      case 'auth.getLoginState':
        return authBridgeService.getLoginState() as T
      case 'auth.getUserProfile':
        return authBridgeService.getUserProfile() as T
      case 'auth.getSession':
        return authBridgeService.getSession() as T
      case 'auth.getAccessToken':
        return authBridgeService.getAccessToken() as T
      case 'auth.logout':
        return authBridgeService.logout() as T
      case 'data.getDict':
        return dataBridgeService.getDict(params) as Promise<T>
      case 'data.getConfig':
        return dataBridgeService.getConfig(params) as Promise<T>
      case 'data.getRemoteOptions':
        return dataBridgeService.getRemoteOptions(params) as Promise<T>
      case 'picker.structure':
        return context.getCapabilityHost?.()?.openStructure(params) as Promise<T>
      case 'picker.file':
        return context.getCapabilityHost?.()?.openFile(params) as Promise<T>
      case 'form.builder':
        return context.getCapabilityHost?.()?.openFormBuilder(params) as Promise<T>
      case 'form.render':
        return context.getCapabilityHost?.()?.openFormRender(params) as Promise<T>
      case 'navigation.setTitle':
        return navigationBridgeService.setTitle(params) as T
      case 'navigation.openPlugin':
        return navigationBridgeService.openPlugin(params) as Promise<T>
      case 'navigation.openRoute':
        return navigationBridgeService.openRoute(params) as Promise<T>
      case 'ui.message':
        return uiBridgeService.message(params) as T
      case 'ui.notify':
        return uiBridgeService.notify(params) as T
      case 'ui.confirm':
        return uiBridgeService.confirm(params) as Promise<T>
      case 'ui.alert':
        return uiBridgeService.alert(params) as Promise<T>
      case 'theme.get':
        return (context.getTheme?.() ?? null) as T
      default:
        throw new Error(`未知基座能力：${method}`)
    }
  }

  return {
    publicApi: {
      version: VERSION,
      pluginName: context.pluginName,
      invoke,
      getCapabilities: () => [...capabilities],
      subscribeTheme: (callback: (theme: PluginThemeState) => void) => {
        if (destroyed || !available) return () => {}
        themeListeners.add(callback)
        // 订阅立即推送一次当前主题，避免首帧闪烁
        if (context.getTheme) callback(context.getTheme())
        return () => {
          themeListeners.delete(callback)
        }
      }
    },
    handleLegacyMessage: (message: any) => {
      console.log('主应用收到插件消息：', message)
    },
    destroy: () => {
      destroyed = true
      stopThemeWatch?.()
      stopThemeWatch = null
      themeListeners.clear()
    }
  }
}

export const pluginBridgeCapabilities = ALL_CAPABILITIES
