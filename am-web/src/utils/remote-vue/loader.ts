import type { Component } from 'vue'
import * as Vue from 'vue'
import * as ElementPlus from 'element-plus'
import * as ElementPlusIcons from '@element-plus/icons-vue'
import { hash } from 'ohash'
import { loadModule, type Options } from 'vue3-sfc-loader'
import { RemoteVueMemoryCache, RemoteVueSourceCache } from './cache'
import { createRemoteRequest } from './request'
import {
  assertAllowedRemoteVueUrl,
  defaultRemoteVueSecurityConfig,
  getRemoteVueCacheKey,
  getRemoteVueRequestUrl,
  normalizeRemoteVueMeta,
  resolveRemoteVueUrl
} from './security'
import type {
  RemoteVueCacheStats,
  RemoteVueComponentMeta,
  RemoteVueLoadOptions,
  RemoteVueSecurityConfig,
  RemoteVueSourceCacheValue
} from '@/types/remote-vue'

const DEFAULT_TTL = 5 * 60 * 1000

export class RemoteVueLoader {
  private componentCache = new RemoteVueMemoryCache<Component>()
  private sourceCache = new RemoteVueSourceCache(DEFAULT_TTL)
  private pendingLoads = new Map<string, Promise<Component>>()
  private moduleCache: Options['moduleCache'] = Object.assign(Object.create(null), {
    vue: Vue,
    'element-plus': ElementPlus,
    '@element-plus/icons-vue': ElementPlusIcons,
    '@am/request': createRemoteRequest()
  })

  constructor(private securityConfig: RemoteVueSecurityConfig = defaultRemoteVueSecurityConfig) {}

  async load(
    input: RemoteVueComponentMeta | string,
    options: RemoteVueLoadOptions & { name?: string } = {}
  ): Promise<Component> {
    const meta = this.normalizeMeta(input, options.name)
    const key = getRemoteVueCacheKey(meta)
    const ttl = options.ttl || meta.cache_ttl || DEFAULT_TTL

    if (!options.force) {
      const cachedComponent = this.componentCache.get(key)
      if (cachedComponent) return cachedComponent

      const pendingLoad = this.pendingLoads.get(key)
      if (pendingLoad) return pendingLoad
    } else {
      this.componentCache.delete(key)
    }

    const loadPromise = this.loadComponent(meta, key, ttl, options).finally(() => {
      this.pendingLoads.delete(key)
    })

    this.pendingLoads.set(key, loadPromise)
    return loadPromise
  }

  async preload(
    input: RemoteVueComponentMeta | string,
    options: RemoteVueLoadOptions & { name?: string } = {}
  ) {
    await this.load(input, options)
  }

  clearByPlugin(plugin: string) {
    this.componentCache.clearByPlugin(plugin)
    this.sourceCache.clearByPlugin(plugin)
  }

  clearAll() {
    this.componentCache.clear()
    this.sourceCache.clear()
    this.pendingLoads.clear()
  }

  getStats(): RemoteVueCacheStats {
    return this.sourceCache.getStats(this.componentCache.size)
  }

  setSecurityConfig(config: RemoteVueSecurityConfig) {
    this.securityConfig = config
  }

  private async loadComponent(
    meta: RemoteVueComponentMeta,
    key: string,
    ttl: number,
    options: RemoteVueLoadOptions
  ) {
    if (options.signal?.aborted) {
      throw new DOMException('远程 Vue 加载已取消', 'AbortError')
    }

    const modulePath = this.getModulePath(meta.url, key, options.force)
    const pathSourceKeys = new Map<string, string>([[modulePath, key]])
    const loaderOptions = this.createLoaderOptions(meta, ttl, options, pathSourceKeys)
    const component = (await loadModule(modulePath, loaderOptions)) as Component

    this.componentCache.set(key, component, {
      ttl,
      plugin: meta.plugin,
      url: meta.url
    })

    return component
  }

  private createLoaderOptions(
    meta: RemoteVueComponentMeta,
    ttl: number,
    loadOptions: RemoteVueLoadOptions,
    pathSourceKeys: Map<string, string>
  ): Options {
    return {
      moduleCache: this.moduleCache,
      getFile: async (path) => {
        const requestUrl = resolveRemoteVueUrl(String(path))
        const sourceKey = pathSourceKeys.get(String(path)) || this.getSourceKey(requestUrl)
        const value = await this.loadSource(requestUrl, sourceKey, ttl, meta, loadOptions)

        return {
          type: this.getFileType(requestUrl),
          getContentData: async (asBinary: boolean) => {
            if (asBinary) {
              return new TextEncoder().encode(value.source).buffer
            }
            return value.source
          }
        }
      },
      addStyle: (style, scopeId) => this.addStyle(style, scopeId),
      log: (type, ...data) => {
        if (import.meta.env.DEV || ['error', 'warn'].includes(type)) {
          const log = console[type as 'error' | 'warn' | 'info'] || console.log
          log('[RemoteVue]', ...data)
        }
      }
    } as Options
  }

  private async loadSource(
    url: string,
    key: string,
    ttl: number,
    meta: RemoteVueComponentMeta,
    options: RemoteVueLoadOptions
  ): Promise<RemoteVueSourceCacheValue> {
    assertAllowedRemoteVueUrl(url, this.securityConfig)

    if (!options.force) {
      const cachedSource = this.sourceCache.get(key)
      if (cachedSource) return cachedSource
    }

    const requestUrl = getRemoteVueRequestUrl(url)
    const response = await fetch(requestUrl, {
      credentials: 'include',
      signal: options.signal
    })

    if (!response.ok) {
      throw new Error(`远程 Vue 请求失败：${response.status} ${response.statusText}`)
    }

    const source = await response.text()
    const value: RemoteVueSourceCacheValue = {
      source,
      url,
      name: meta.name,
      plugin: meta.plugin,
      version: meta.version,
      hash: meta.hash
    }

    this.sourceCache.set(key, value, {
      ttl,
      plugin: meta.plugin,
      url
    })

    return value
  }

  private normalizeMeta(input: RemoteVueComponentMeta | string, name?: string) {
    const meta = normalizeRemoteVueMeta(input, name)
    const url = resolveRemoteVueUrl(meta.url)
    assertAllowedRemoteVueUrl(url, this.securityConfig)
    return {
      ...meta,
      url
    }
  }

  private getSourceKey(url: string) {
    return `remote-vue:source:${hash(url)}`
  }

  private getModulePath(url: string, key: string, force = false) {
    const parsedUrl = new URL(url)
    parsedUrl.searchParams.set('am_widget_key', hash(key))
    if (force) {
      parsedUrl.searchParams.set('am_widget_force', String(Date.now()))
    }
    return parsedUrl.toString()
  }

  private getFileType(url: string) {
    const pathname = new URL(url).pathname
    const match = pathname.match(/\.[a-z0-9]+$/i)
    return match?.[0] || '.vue'
  }

  private addStyle(style: string, scopeId?: string) {
    if (typeof document === 'undefined') return

    const styleId = `am-remote-vue-style-${hash({ style, scopeId })}`
    if (document.getElementById(styleId)) return

    const styleElement = document.createElement('style')
    styleElement.id = styleId
    styleElement.type = 'text/css'
    styleElement.dataset.remoteVue = 'true'
    if (scopeId) styleElement.dataset.scopeId = scopeId
    styleElement.textContent = style
    document.head.appendChild(styleElement)
  }
}
