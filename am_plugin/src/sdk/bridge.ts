/**
 * Bridge 单例管理
 *
 * 插件通过 qiankun 加载时，基座通过 props.hostBridge 注入 Bridge API。
 * 本模块负责缓存 bridge 引用，并提供类型安全的 invoke 封装。
 *
 * 插件页面中请直接使用 invoke() 调用基座能力，无需关心 bridge 初始化细节。
 */
import type { PluginBridgeApi } from '@/bridge/plugin/types'

let _bridge: PluginBridgeApi | null = null

/** 由 qiankun mount 阶段调用，注入基座提供的 bridge */
export const setBridge = (bridge: PluginBridgeApi) => {
  _bridge = bridge
}

/** 获取当前 bridge 实例 */
export const getBridge = (): PluginBridgeApi => {
  if (!_bridge) {
    throw new Error('[Plugin SDK] Bridge 未初始化，请确认当前通过基座 qiankun 打开')
  }
  return _bridge
}

/** 是否已注入 bridge */
export const hasBridge = (): boolean => _bridge !== null

/**
 * 调用基座能力（类型安全封装）
 *
 * @example
 * ```ts
 * const user = await invoke('auth.getUserProfile')
 * const dict = await invoke('data.getDict', { code: 'structure_type' })
 * await invoke('ui.message', { type: 'success', message: '操作成功' })
 * ```
 */
export const invoke = <T = any>(method: string, params?: any): Promise<T> => {
  return getBridge().invoke<T>(method, params)
}

/** 获取基座已开放的能力列表 */
export const getCapabilities = (): string[] => {
  if (!_bridge) return []
  return _bridge.getCapabilities()
}
