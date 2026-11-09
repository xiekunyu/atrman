/**
 * 运行时环境配置
 *
 * 统一「插件标识」与「接口请求地址」的取值来源：
 * - 插件标识：来自构建期环境变量 VITE_PLUGIN_NAME。每个插件编译时写死自己的标识，
 *   插件内几乎所有接口都会用到它，因此统一从环境变量读取，而不是依赖基座 bridge 注入。
 * - 接口地址：开发模式读取环境变量（走本地 vite 代理）；生产模式直接使用当前访问域名，
 *   使编译后的产物随部署域名自适应，无需按环境重新编译。
 *
 * @module utils/env
 */

import { qiankunWindow } from 'vite-plugin-qiankun/dist/helper'
import AppConfig from '@/config'

/** 当前插件标识（构建期 VITE_PLUGIN_NAME） */
export function getPluginName(): string {
  return (import.meta.env.VITE_PLUGIN_NAME as string) || ''
}

/**
 * 接口请求基础地址
 * - 开发（独立运行）：环境变量 VITE_API_URL（如 /api，由 vite 代理 rewrite 掉前缀后转发到后端）
 * - 开发（被线上基座 qiankun 加载）：此时 window.location 是基座域名，相对路径会绕过本地代理
 *   直接打到线上，因此必须用本地 dev server 的绝对地址，强制请求走 vite proxy 转发
 * - 生产：当前访问域名 origin（后端接口与前端部署在同域名根路径，无需 /api 前缀，也无需重新编译）
 */
export function getApiBaseURL(): string {
  if (import.meta.env.DEV) {
    const prefix = (import.meta.env.VITE_API_URL as string) || ''
    if (qiankunWindow.__POWERED_BY_QIANKUN__) {
      // dev server 固定开启 https（见 vite.config.ts 的 basicSsl）
      return `https://localhost:${import.meta.env.VITE_PORT}${prefix}`
    }
    return prefix
  }
  if (typeof window !== 'undefined' && window.location?.origin) {
    return window.location.origin
  }
  return (import.meta.env.VITE_API_URL as string) || ''
}

/**
 * 会员中心（am_member）前台入口，用于登录/注册/个人中心整页跳转。
 *
 * - 优先读后台「网站配置 - 统一认证入口」（systemInfo.auth_entry，基座 /pub/system/systemInfo 下发）；
 * - 未配置（或协议相对 //host 形态）时回退构建期 VITE_MEMBER_ENTRY，再回退生产默认 '/app/am_member'
 *   （同域部署，登录态经同域 localStorage 契约 atrman:session 共享）；
 * - systemInfo 由 App.vue 异步拉取，首屏极早期取到的是兜底值，与历史行为一致。
 *
 * 注意：跨端口直连开发时 localStorage 不跨端口共享，登录闭环无法完整体验，
 * 纯 UI 开发只需验证生成的跳转 URL；完整闭环请用同域 host/反向代理联调。
 */
export function getMemberEntry(): string {
  const configured = String(AppConfig.systemInfo.auth_entry || '').trim()
  const fallback = (import.meta.env.VITE_MEMBER_ENTRY as string) || '/app/am_member'
  const entry = configured && !configured.startsWith('//') ? configured : fallback
  return entry.replace(/\/+$/, '')
}
