export type BridgeMethod =
  | 'auth.getLoginState'
  | 'auth.getUserProfile'
  | 'auth.getSession'
  | 'auth.getAccessToken'
  | 'auth.logout'
  | 'data.getDict'
  | 'data.getConfig'
  | 'data.getRemoteOptions'
  | 'picker.structure'
  | 'picker.file'
  | 'form.builder'
  | 'form.render'
  | 'navigation.setTitle'
  | 'navigation.openPlugin'
  | 'navigation.openRoute'
  | 'ui.message'
  | 'ui.notify'
  | 'ui.confirm'
  | 'ui.alert'
  | 'theme.get'

/**
 * 基座主题快照（子应用只读同步，不包含菜单布局 menuType）
 */
export interface PluginThemeState {
  systemThemeType: string
  systemThemeMode: string
  systemThemeColor: string
  menuThemeType: string
  customRadius: string
}

export interface BridgeCancelledResult {
  cancelled: true
}

export interface PluginBridgeApi {
  version: string
  pluginName: string
  invoke<T = any>(method: BridgeMethod | string, params?: any): Promise<T>
  getCapabilities(): string[]
  /** 订阅基座主题变化，返回取消订阅函数 */
  subscribeTheme?(callback: (theme: PluginThemeState) => void): () => void
}

export interface PluginBridgeContext {
  plugin: Record<string, any>
  pluginName: string
  entryUrl: string
  manifest: Record<string, any>
  setTitle?: (title: string) => void
  getCapabilityHost?: () => PluginCapabilityHostExpose | undefined
}

export interface PluginBridgeHost {
  publicApi: PluginBridgeApi
  handleLegacyMessage: (message: any) => void
  destroy: () => void
}

export interface StructurePickerParams {
  type?: number
  title?: string
  defaultValue?: any
  displayData?: any[]
  showMainDept?: boolean
}

export interface FilePickerParams {
  title?: string
  multiple?: boolean
  type?: number
  accept?: string
  defaultValue?: any
  displayData?: any[]
}

export interface FormBuilderParams {
  title?: string
  items?: any[]
  showImport?: boolean
  showPreview?: boolean
  showExport?: boolean
}

export interface FormRenderParams {
  title?: string
  items: any[]
  model?: Record<string, any>
  useBuilderConfig?: boolean
  formProps?: Record<string, any>
}

export interface PluginCapabilityHostExpose {
  openStructure: (params?: StructurePickerParams) => Promise<any>
  openFile: (params?: FilePickerParams) => Promise<any>
  openFormBuilder: (params?: FormBuilderParams) => Promise<any>
  openFormRender: (params: FormRenderParams) => Promise<any>
}
