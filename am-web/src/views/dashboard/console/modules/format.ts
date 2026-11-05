/**
 * 工作台小部件共享格式化工具
 */

/** 字节数转人性化文本 */
export function humanSize(bytes: number): string {
  if (!bytes || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let value = bytes
  let index = 0
  while (value >= 1024 && index < units.length - 1) {
    value /= 1024
    index++
  }
  return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

/**
 * 归一化时间输入：后端模型字段可能返回 'Y-m-d H:i:s' 字符串，也可能是 Unix 秒
 */
function toDate(value: number | string): Date | null {
  if (typeof value === 'number') {
    return value > 0 ? new Date(value * 1000) : null
  }
  if (!value) return null
  const date = new Date(value.replace(/-/g, '/'))
  return isNaN(date.getTime()) ? null : date
}

/** 时间戳/日期字符串转相对时间（刚刚 / x分钟前 / x小时前 / 昨天 HH:mm / MM-DD HH:mm） */
export function relativeTime(timestamp: number | string): string {
  const date = toDate(timestamp)
  if (!date) return '-'
  const now = Math.floor(Date.now() / 1000)
  const diff = now - Math.floor(date.getTime() / 1000)
  if (diff < 60) return '刚刚'
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  const time = `${pad(date.getHours())}:${pad(date.getMinutes())}`
  if (diff < 172800) return `昨天 ${time}`
  if (diff < 86400 * 30) return `${date.getMonth() + 1}-${date.getDate()} ${time}`
  return formatDateTime(timestamp)
}

/** 时间戳/日期字符串转 YYYY-MM-DD HH:mm */
export function formatDateTime(timestamp: number | string): string {
  const date = toDate(timestamp)
  if (!date) return '-'
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function pad(value: number): string {
  return value < 10 ? `0${value}` : String(value)
}

/** 环比百分比文本（上期为 0 或持平不显示） */
export function weekOverWeek(current: number, last: number): string | null {
  if (!last) return null
  const rate = ((current - last) / last) * 100
  if (rate === 0) return null
  const sign = rate > 0 ? '+' : ''
  return `${sign}${rate.toFixed(0)}%`
}
