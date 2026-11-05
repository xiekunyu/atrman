import request from '@/utils/http'

/** 商店应用（数据来自远程市场，不落本地库） */
export interface StoreApp {
  id: string
  name: string
  title: string
  icon: string
  description: string
  /** 富文本详情（开发者用 WangEditor 编辑，前端消毒后渲染） */
  detail?: string
  author: string
  version: string
  category: string
  tags: string[]
  screenshots: string[]
  download_count: number
  is_recommend: number
  /** 是否付费应用 */
  is_paid: number
  /** 价格（支持小数，单位由 price_unit 定义） */
  price: number
  /** 价格单位：元、积分、金币等自定义形式 */
  price_unit: string
  /** 当前市场账号是否已购买（付费应用） */
  purchased: number
  /** 是否需要授权（付费应用=1，安装后有 license 校验与心跳） */
  license_required: number
  /** 授权类型：perpetual 永久 / subscription 订阅 */
  license_type?: string
  /** 更新日志 */
  changelog?: string
  /** 本地状态（后端合并） */
  installed: boolean
  local_version: string
  local_status: number
  plugin_id: number | null
  has_update: boolean
  /** 本地授权状态：1有效 0过期 -1吊销 -2校验失败，免费应用为 null */
  license_status: number | null
}

export interface StoreCategory {
  key: string
  label: string
  icon: string
}

/** 应用版本历史条目 */
export interface StoreVersion {
  version: string
  release_date: string
  changelog: string
  /** 是否必装版本 */
  is_required: number
  /** 支持的基座版本 */
  support_system_version: string
}

export interface StoreListParams {
  category?: string
  keyword?: string
  recommend?: number
  installed?: number
  page?: number
  limit?: number
  orderField?: string
  orderType?: 'asc' | 'desc'
}

/** 分页列表响应 */
export interface PaginatedListResult<T> {
  list: T[]
  total: number
  page: number
}

/** 市场账号绑定状态 */
export interface MarketStatus {
  bound: boolean
  username?: string
  nickname?: string
  expires_at?: number
  expired?: boolean
  instance_id: string
  provider: string
}

/** 授权记录（付费应用） */
export interface LicenseItem {
  plugin_id: number
  name: string
  title: string
  version: string
  license_id: string
  license_type: string
  issued_at: number
  expires_at: number
  status: number
  last_check_time: number
  fail_count: number
  last_message: string
  plugin_status: number
  error_message: string
}

export interface PluginUpdateInfo {
  id: string
  name: string
  title: string
  current_version: string
  new_version: string
  is_paid: number
  changelog: string
}

export interface SystemUpdateInfo {
  has_update: boolean
  current_version: string
  latest_version: string
  changelog: string
  release_date: string
}

/** 升级预检结果（级联判定 + 下载前兼容性硬门槛） */
export interface StoreUpdatePreflight {
  has_update: boolean
  current_version: string
  target_version: string
  target_title: string
  /** 目标启用中 或 存在启用中依赖方 → 需级联 */
  need_cascade: boolean
  /** 将临时停用并在升级后自动恢复的依赖方 */
  will_disable: { name: string; title: string }[]
  /** 无环且可自动级联 */
  can_cascade: boolean
  block_reason: string
  /** 付费未购买（can_cascade=false 时可能出现） */
  need_purchase?: boolean
  price?: number
  buy_url?: string
  /** 目标安装包体积（字节，全量包） */
  package_size: number
  /** 目标版本更新日志 */
  changelog: string
  /** 目标版本发布日期 YYYY-MM-DD */
  release_date: string
  /** 目标版本要求的最低基座版本（空串=未声明） */
  support_system_version: string
  /** 当前基座版本 */
  current_base_version: string
  /** 是否兼容当前基座；false 时禁止升级 */
  compatible: boolean
  /** 兼容性说明文案 */
  compatibility_message: string
}

/** 级联升级结果（controller 将 cascadeUpdate 的 data 原样透传） */
export interface StoreCascadeResult {
  ok: boolean
  message: string
  /** 成功且已自动重载（Linux SIGUSR1） */
  reloaded?: boolean
  /** 成功但需手动重启才完全生效 */
  need_reload?: boolean
  reload_message?: string
  /** 失败已回滚 */
  reverted?: boolean
  file_rolled_back?: boolean
  db_rolled_back?: boolean | null
  db_note?: string
  /** 重启用成功的依赖方名 */
  affected_enable?: string[]
  /** 回滚后仍未能动恢复启用的插件名（需人工启用） */
  enable_failed?: string[]
  target?: { name: string; from_version: string; to_version: string }
  /** 付费未购买 */
  need_purchase?: boolean
  price?: number
  buy_url?: string
}

export default {
  // ─────────── 应用浏览与安装 ───────────

  /** 获取应用列表（分页） */
  list(params: StoreListParams = {}) {
    return request.get<any>({
      url: '/plugin/store/index',
      params,
      decompress: true
    }).then((res: any) => ({
      list: (res?.data ?? []) as StoreApp[],
      total: (res?.count ?? 0) as number,
      page: (res?.page ?? 1) as number
    })) as Promise<PaginatedListResult<StoreApp>>
  },

  /** 获取应用详情 */
  detail(id: string) {
    return request.get<StoreApp>({
      url: '/plugin/store/read',
      params: { id }
    })
  },

  /** 获取分类列表 */
  categories() {
    return request.get<StoreCategory[]>({
      url: '/plugin/store/categories'
    })
  },

  /** 获取应用版本历史列表 */
  versions(appId: string) {
    return request.get<StoreVersion[]>({
      url: '/plugin/store/versions',
      params: { app_id: appId }
    })
  },

  /** 安装应用（付费未购买时返回 need_purchase + buy_url）
   * @param id 应用标识
   * @param version 指定版本号，空字符串表示最新版 */
  install(id: string, version: string = '') {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/store/install',
      data: { id, version }
    })
  },

  /** 更新应用（cascade=true 走级联升级：临时停用依赖方→升级→自动重启恢复）
   * 级联为分钟级同步请求（下载+快照+多插件停用/重启），单独放宽超时；错误保留可见性 */
  update(id: string, cascade = false) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/store/update',
      data: { id, cascade },
      timeout: cascade ? 600000 : undefined
    })
  },

  /** 升级预检：返回是否需要级联、将临时停用哪些依赖方、可否级联 */
  preflight(id: string) {
    return request.get<StoreUpdatePreflight>({
      url: '/plugin/store/update/preflight',
      params: { id },
      timeout: 60000
    })
  },

  /** 检查所有插件更新 */
  checkUpdate() {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/store/check-update'
    })
  },

  /** 检查系统更新 */
  systemUpdate() {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/store/system-update'
    })
  },

  // ─────────── 市场账号 ───────────

  /** 绑定市场账号 */
  marketLogin(data: { username: string; password: string }) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/store/market/login',
      data
    })
  },

  /** 获取市场账号绑定状态 */
  marketStatus() {
    return request.get<MarketStatus>({
      url: '/plugin/store/market/status'
    })
  },

  /** 解绑市场账号（返回更新后的状态） */
  marketLogout() {
    return request.post<MarketStatus>({
      url: '/plugin/store/market/logout'
    })
  },

  // ─────────── 授权管理（付费应用） ───────────

  /** 授权列表 */
  licenseList() {
    return request.get<LicenseItem[]>({
      url: '/plugin/store/license/index'
    })
  },

  /** 手动刷新授权 */
  licenseRefresh(id: number) {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/store/license/refresh',
      data: { id }
    })
  }
}