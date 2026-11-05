import type { Component } from 'vue'

export interface RemoteVueComponentMeta {
  plugin?: string
  name: string
  title?: string
  description?: string
  type?: 'widget' | 'page' | 'panel' | string
  entry?: string
  url: string
  version?: string
  hash?: string
  integrity?: string
  cache_ttl?: number
  preload?: boolean
  props?: Record<string, any>
  plugin_name?: string
}

export interface RemoteVueSecurityConfig {
  enabled: boolean
  allowedOrigins: string[]
  allowedPathPrefixes: string[]
  allowDevHttp?: boolean
}

export interface RemoteVueLoadOptions {
  force?: boolean
  ttl?: number
  signal?: AbortSignal
}

export interface RemotePostOptions {
  showSuccessMessage?: boolean
  showErrorMessage?: boolean
}

export interface RemoteVueCacheStats {
  memorySize: number
  persistentSize: number
  hit: number
  miss: number
  expired: number
}

export interface RemoteVueCacheItem<T> {
  key: string
  value: T
  timestamp: number
  ttl: number
  plugin?: string
  url?: string
  accessCount: number
  lastAccessTime: number
}

export interface RemoteVueSourceCacheValue {
  source: string
  url: string
  name?: string
  plugin?: string
  version?: string
  hash?: string
}

export interface RemoteVueLoadedResult {
  component: Component
  meta: RemoteVueComponentMeta
}
