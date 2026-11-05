import type { BridgeMethod } from './types'

/**
 * Bridge 权限作用域的权威清单：宿主按此表把 manifest.bridge.scopes 展开为子应用可调用的方法。
 * 打包表单等配置界面直接消费本表，保证「可勾选的权限」与「宿主实际放行的能力」永不脱节。
 */
export const SCOPE_METHODS: Record<string, BridgeMethod[]> = {
  'auth.profile': ['auth.getUserProfile'],
  'auth.session': ['auth.getSession', 'auth.getAccessToken'],
  'data.dict': ['data.getDict'],
  'data.config': ['data.getConfig'],
  'data.remoteOptions': ['data.getRemoteOptions'],
  'picker.structure': ['picker.structure'],
  'picker.file': ['picker.file'],
  'form.builder': ['form.builder'],
  'form.render': ['form.render'],
  navigation: ['navigation.setTitle', 'navigation.openPlugin', 'navigation.openRoute'],
  'ui.feedback': ['ui.message', 'ui.notify', 'ui.confirm', 'ui.alert'],
  theme: ['theme.get']
}

export interface BridgeScopeOption {
  /** 写入 manifest.bridge.scopes 的作用域标识 */
  value: string
  /** 作用域中文名 */
  label: string
  /** 该作用域放行的方法清单，用于勾选时说明授权范围 */
  methods: BridgeMethod[]
}

const SCOPE_LABELS: Record<string, string> = {
  'auth.profile': '读取用户资料',
  'auth.session': '读取会话与令牌',
  'data.dict': '读取字典数据',
  'data.config': '读取系统配置',
  'data.remoteOptions': '拉取远程选项',
  'picker.structure': '组织架构选择器',
  'picker.file': '文件选择器',
  'form.builder': '表单设计器',
  'form.render': '表单渲染器',
  navigation: '页面与插件跳转',
  'ui.feedback': '消息、通知与弹窗',
  theme: '同步基座主题'
}

/** 全部可授权作用域；新增作用域漏配文案时回退为标识本身并在开发环境告警，保证选项不缺项。 */
export const BRIDGE_SCOPE_OPTIONS: BridgeScopeOption[] = Object.entries(SCOPE_METHODS).map(
  ([value, methods]) => {
    const label = SCOPE_LABELS[value]
    if (!label && import.meta.env.DEV) {
      console.warn(`[PluginBridge] 作用域 ${value} 缺少中文文案，请补充 SCOPE_LABELS`)
    }
    return { value, label: label || value, methods }
  }
)
