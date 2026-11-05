import request from '@/utils/http'

/** 核心指标统计 */
export interface DashboardStats {
  users: { total: number; today: number; week: number; last_week: number }
  logins: { today: number; week: number; last_week: number }
  active: { week: number }
  apps: { total: number; enabled: number; error: number }
  files: { size: number }
  operates: { today: number; week: number; last_week: number }
}

/** 登录趋势（按天） */
export interface LoginTrendItem {
  date: string
  success: number
  fail: number
}

/** 最近新增用户（模型字段可能为 Unix 秒或 'Y-m-d H:i:s' 字符串） */
export interface NewUserItem {
  id: number
  username: string
  nickname: string
  account_type: number
  status: number
  create_time: number | string
  last_login_time: number | string | null
}

/** 系统动态（操作日志） */
export interface ActivityItem {
  id: number
  username: string
  business_name: string
  method: string
  ip: string
  ip_location: string
  create_time: number | string
}

/** 应用状态统计（应用中心头部） */
export interface AppStats {
  total: number
  counts: { enabled: number; installed: number; disabled: number; error: number }
  dev: number
}

/** 存储用量 */
export interface StorageUsage {
  total: number
  size: number
  size_human: string
  by_type: Array<{
    type: number
    name: string
    count: number
    size: number
    size_human: string
  }>
}

/** 服务器健康状态（精简监控数据） */
export interface ServerHealth {
  overview: {
    status: string
    statusText: string
    hostname: string
    platform: string
    uptimeHuman: string
    updatedAt: string
  }
  cpu: { cores: number; usagePercent: number | null; loadAvg: number[] }
  memory: { usedPercent: number | null; totalHuman: string; usedHuman: string }
  disk: {
    usedPercent: number | null
    totalHuman: string
    freeHuman: string
    items: Array<{ path: string; usedPercent: number; totalHuman: string; usedHuman: string }>
  }
  php: { version: string; sapi: string; opcacheEnabled: boolean | null }
  processes: { php: number; webman: number }
}

/**
 * 基座工作台统计 API
 * 后端控制器：app/controller/system/DashboardController.php（仅需登录态）
 */
export default {
  /** 核心指标卡片数据 */
  stats() {
    return request.get<DashboardStats>({ url: '/sys/dashboard/stats' })
  },

  /** 登录趋势（近 N 天成功/失败） */
  loginTrend(params: { days?: number } = { days: 30 }) {
    return request.get<LoginTrendItem[]>({ url: '/sys/dashboard/loginTrend', params })
  },

  /** 最近新增用户 */
  newUsers(params: { limit?: number } = { limit: 8 }) {
    return request.get<NewUserItem[]>({ url: '/sys/dashboard/newUsers', params })
  },

  /** 系统动态（最近业务操作） */
  activities(params: { limit?: number } = { limit: 10 }) {
    return request.get<ActivityItem[]>({ url: '/sys/dashboard/activities', params })
  },

  /** 应用状态统计 */
  appStats() {
    return request.get<AppStats>({ url: '/sys/dashboard/appStats' })
  },

  /** 存储用量（按文件类型） */
  storage() {
    return request.get<StorageUsage>({ url: '/sys/dashboard/storage' })
  },

  /** 服务器健康状态 */
  serverHealth() {
    return request.get<ServerHealth>({ url: '/sys/dashboard/serverHealth' })
  }
}
