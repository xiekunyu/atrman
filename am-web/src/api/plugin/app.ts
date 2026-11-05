import request from '@/utils/http'

export interface PluginListParams extends Record<string, any> {}

export interface PluginActionPayload {
  id?: number | string
  name?: string
}

export interface InstallPackagePayload {
  file_id?: number | string
  overwrite?: 0 | 1 | boolean
}

export interface RestorePackagePayload {
  file_id?: number | string
}

export interface PackageInspectPayload {
  file_id: number | string
}

export interface WidgetListParams {
  app_name?: string
  plugin_id?: number | string
  id?: number | string
  type?: string
  status?: number | string
}

export interface CheckNameResult {
  valid: boolean
  name: string
  available: boolean
  reason: string
  message: string
}

export interface IssueAppTicketResult {
  app_id: string
  entry: string
  ticket: string
  /** 已拼接 ?ticket= 的目标应用入口地址 */
  url: string
}

export default {
  list(params: PluginListParams = {}) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/app/index',
      params
    })
  },
  read(id: number) {
    return request.get<Api.Common.ApiData>({
      url: `/plugin/app/read?id=${id}`
    })
  },
  // 宿主入口按插件标识解析：标识在实例内唯一且不随备份迁移变化（记录 id 会变）。
  readByName(name: string) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/app/readByName',
      params: { name }
    })
  },
  save(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/save',
      data
    })
  },
  checkName(name: string) {
    return request.get<CheckNameResult>({
      url: '/plugin/app/checkName',
      params: { name }
    })
  },
  update(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/update',
      data
    })
  },
  saveAuth(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/auth',
      data
    })
  },
  dependencySave(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/dependency/save',
      data
    })
  },
  dependencyUpdate(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/dependency/update',
      data
    })
  },
  dependencyDelete(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/dependency/delete',
      data
    })
  },
  scan() {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/scan'
    })
  },
  packageInspect(data: PackageInspectPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/packageInspect',
      data
    })
  },
  install(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/install',
      data
    })
  },
  scaffold(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/scaffold',
      data
    })
  },
  installPackage(data: FormData | InstallPackagePayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/installPackage',
      data,
      ...(data instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : {})
    })
  },
  restorePackage(data: FormData | RestorePackagePayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/restorePackage',
      data,
      ...(data instanceof FormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : {})
    })
  },
  uninstall(data: PluginActionPayload & Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/uninstall',
      data
    })
  },
  backup(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/backup',
      data
    })
  },
  enable(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/enable',
      data
    })
  },
  disable(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/disable',
      data
    })
  },
  reload(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/reload',
      data
    })
  },
  composerCheck(data: PluginActionPayload) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/composer/check',
      data
    })
  },
  composerInstall(data: PluginActionPayload & { targets?: Record<string, string> }) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/composer/install',
      data
    })
  },
  composerStatus(job_id: string) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/app/composer/status',
      params: { job_id }
    })
  },
  composerReload() {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/app/composer/reload'
    })
  },
  // 应用门户免登签票（基座通用接口，需登录态）：为当前登录人签发绑定目标应用的一次性票据，
  // 返回已拼好 ?ticket= 的目标地址，供详情页新标签打开应用前台。
  issueTicket(data: { app_id: string; entry?: 'entry' | 'manage_entry' }) {
    return request.post<IssueAppTicketResult>({
      url: '/oauth/ticket/issue',
      data: { app_id: data.app_id, entry: data.entry || 'entry' },
      // 签票失败由调用方降级直开原始 entry，抑制拦截器重复弹窗
      showErrorMessage: false
    })
  },
  widgetList(params: WidgetListParams = {}) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/widget/index',
      params
    })
  },
  widgetInfo(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/widget/read',
      params
    })
  },
  widgetSave(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/widget/save',
      data
    })
  },
  widgetUpdate(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/widget/update',
      data
    })
  },
  widgetDelete(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/widget/delete',
      data
    })
  }
}
