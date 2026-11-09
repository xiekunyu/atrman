/**
 * 基座主题同步（Bridge 显式通道）
 *
 * 设计目标：
 * - 子应用的深色模式、主题色、菜单主题风格、圆角等「主题相关配置」实时跟随基座；
 * - 「菜单布局 menuType」不跟随基座，固定为子应用自己的布局（左侧菜单）。
 *
 * 实现方式：
 * - 通过 hostBridge 的 theme.get 读取基座当前主题快照；
 * - 通过 hostBridge.subscribeTheme 订阅基座主题变化，实时更新子应用 store 的主题字段。
 * - 仅更新 store 字段，不操作 documentElement / CSS 变量：基座与子应用共用同一
 *   documentElement（qiankun 未开样式隔离），深色 class 与 Element Plus 主题变量
 *   已由基座统一控制，子应用组件天然继承，避免两端互相覆盖。
 */
import { useSettingStore } from '@/store/modules/setting'
import { SystemThemeEnum, MenuThemeEnum, MenuTypeEnum } from '@/enums/appEnum'
import { hasBridge, invoke, getBridge } from './bridge'
import type { PluginThemeState } from '@/bridge/plugin/types'

/**
 * 将基座主题快照应用到子应用 store（只同步主题字段，不动 menuType）
 */
const applyThemeState = (theme?: PluginThemeState | null) => {
  if (!theme) return
  const settingStore = useSettingStore()
  settingStore.systemThemeType = theme.systemThemeType as SystemThemeEnum
  settingStore.systemThemeMode = theme.systemThemeMode as SystemThemeEnum
  settingStore.systemThemeColor = theme.systemThemeColor
  settingStore.menuThemeType = theme.menuThemeType as MenuThemeEnum
  if (theme.customRadius != null) {
    settingStore.customRadius = theme.customRadius
  }
}

/**
 * 启动主题同步
 *
 * - 无论何种模式，都先把菜单布局固定为左侧，保证子应用布局独立于基座；
 * - 在 qiankun（hasBridge）环境下，读取并订阅基座主题变化。
 *
 * @returns 取消订阅函数（子应用 unmount 时调用）
 */
export const setupBaseThemeSync = async (): Promise<() => void> => {
  const settingStore = useSettingStore()

  // 菜单布局不跟随基座：子应用始终使用左侧菜单布局
  settingStore.switchMenuLayouts(MenuTypeEnum.LEFT)

  // 独立运行（无基座）时无需同步
  if (!hasBridge()) {
    return () => {}
  }

  // 首次读取基座主题
  try {
    const theme = await invoke<PluginThemeState>('theme.get')
    applyThemeState(theme)
  } catch (e) {
    console.warn('[Plugin] 读取基座主题失败，将沿用初始主题:', e)
  }

  // 订阅基座主题实时变化
  const bridge = getBridge()
  if (typeof bridge.subscribeTheme === 'function') {
    return bridge.subscribeTheme((theme) => applyThemeState(theme))
  }
  return () => {}
}
