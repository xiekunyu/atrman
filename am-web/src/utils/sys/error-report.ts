/**
 * 前端错误上报客户端（T7 可观测性）。
 *
 * 把全局未捕获异常投递到基座 POST /sys/errorReport/report：
 * - 裸 axios 直发，绕过业务 axios 实例拦截器——上报请求不能触发 401 续期/强退，也不能弹错误提示；
 * - 采样率由 VITE_ERROR_SAMPLE_RATE 控制（0~1，缺省 1 即全量）；
 * - 同 type+message+stack 头的错误 5 分钟内只报一次，防单点故障刷屏把上报接口打爆；
 * - 客户端先脱敏（Bearer 令牌 / 查询串口令），服务端入库前再兜底一遍；
 * - 纯 fire-and-forget：任何异常都在模块内吞掉，错误处理链路自身绝不允许再抛错。
 *
 * @module utils/sys/error-report
 */
import axios from 'axios'
import { getApiBaseURL } from '@/utils/env'

/** 当前工作区的应用标识（与后端 ErrorReportService 白名单对齐） */
const APP_ID = 'am_web'

const REPORT_URL = '/sys/errorReport/report'
const REQUEST_TIMEOUT = 8000
const DEDUP_WINDOW = 5 * 60 * 1000
const DEDUP_CAP = 100
const MAX_MESSAGE = 1000
const MAX_STACK = 4000
const MAX_URL = 500
const MAX_INFO = 200

export type ErrorReportType = 'vue' | 'script' | 'promise' | 'resource'

export interface ErrorReportInput {
  type: ErrorReportType
  message: string
  stack?: string
  url?: string
  /** Vue errorHandler 的生命周期钩子信息等附加上下文 */
  info?: string
}

/** 同类错误去重表：key -> 最近一次上报时间戳 */
const recent = new Map<string, number>()

function sampleRate(): number {
  const raw = Number(import.meta.env.VITE_ERROR_SAMPLE_RATE)
  if (!Number.isFinite(raw) || raw < 0 || raw > 1) return 1
  return raw
}

/** 脱敏：Bearer 令牌与 URL 查询串中的令牌/口令/密钥参数打码（与后端同口径） */
function scrub(text: string): string {
  if (!text) return ''
  const out = text.replace(/(Bearer\s+)[A-Za-z0-9._-]+/gi, '$1***')
  return out.replace(
    /([?&](?:token|access_token|refresh_token|authorization|password|passwd|secret|api_key|apikey|key)=)[^&#]+/gi,
    '$1***'
  )
}

function truncate(text: string, max: number): string {
  return text.length > max ? text.slice(0, max) : text
}

/**
 * 上报一条前端错误。无返回值、不抛错、不阻塞调用方。
 */
export function reportError(input: ErrorReportInput): void {
  try {
    const message = truncate(scrub(String(input.message || '')), MAX_MESSAGE)
    if (!message) return
    if (Math.random() > sampleRate()) return

    const stackParts = [truncate(scrub(String(input.stack ?? '')), MAX_STACK)]
    if (input.info) stackParts.push('lifecycle: ' + truncate(scrub(String(input.info)), MAX_INFO))
    const stack = stackParts.filter(Boolean).join('\n')

    const dedupKey = `${input.type}|${message}|${stack.slice(0, 120)}`
    const now = Date.now()
    const last = recent.get(dedupKey)
    if (last !== undefined && now - last < DEDUP_WINDOW) return
    recent.set(dedupKey, now)
    // 容量兜底：超过上限顺手清掉过期键，避免长会话 Map 无限增长
    if (recent.size > DEDUP_CAP) {
      for (const [key, ts] of recent) {
        if (now - ts >= DEDUP_WINDOW) recent.delete(key)
      }
    }

    void axios
      .post(
        `${getApiBaseURL()}${REPORT_URL}`,
        {
          app: APP_ID,
          level: 'error',
          type: input.type,
          message,
          stack,
          url: truncate(scrub(input.url || window.location.href), MAX_URL)
        },
        {
          timeout: REQUEST_TIMEOUT,
          withCredentials: import.meta.env.VITE_WITH_CREDENTIALS === 'true'
        }
      )
      .catch(() => {
        /* 网络失败/端点不可用：静默，不能在错误处理器里再制造错误 */
      })
  } catch {
    /* 上报本身永不抛错 */
  }
}
