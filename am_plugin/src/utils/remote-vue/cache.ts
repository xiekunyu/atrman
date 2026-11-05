import type {
  RemoteVueCacheItem,
  RemoteVueCacheStats,
  RemoteVueSourceCacheValue
} from '@/types/remote-vue'

const DEFAULT_TTL = 5 * 60 * 1000
const DEFAULT_MAX_SIZE = 50
const STORAGE_PREFIX = 'am_widget:'

export class RemoteVueMemoryCache<T> {
  private cache = new Map<string, RemoteVueCacheItem<T>>()

  constructor(
    private ttl = DEFAULT_TTL,
    private maxSize = DEFAULT_MAX_SIZE
  ) {}

  set(key: string, value: T, options: { ttl?: number; plugin?: string; url?: string } = {}) {
    const now = Date.now()
    this.cache.set(key, {
      key,
      value,
      timestamp: now,
      ttl: options.ttl || this.ttl,
      plugin: options.plugin,
      url: options.url,
      accessCount: 1,
      lastAccessTime: now
    })
    this.evictLRU()
  }

  get(key: string): T | null {
    const item = this.cache.get(key)
    if (!item) return null

    if (Date.now() - item.timestamp > item.ttl) {
      this.cache.delete(key)
      return null
    }

    item.accessCount++
    item.lastAccessTime = Date.now()
    return item.value
  }

  delete(key: string) {
    return this.cache.delete(key)
  }

  clear() {
    this.cache.clear()
  }

  clearByPlugin(plugin: string) {
    let count = 0
    for (const [key, item] of this.cache.entries()) {
      if (item.plugin === plugin) {
        this.cache.delete(key)
        count++
      }
    }
    return count
  }

  get size() {
    return this.cache.size
  }

  private evictLRU() {
    while (this.cache.size > this.maxSize) {
      let lruKey = ''
      let minAccessCount = Infinity
      let oldestTime = Infinity

      for (const [key, item] of this.cache.entries()) {
        if (
          item.accessCount < minAccessCount ||
          (item.accessCount === minAccessCount && item.lastAccessTime < oldestTime)
        ) {
          lruKey = key
          minAccessCount = item.accessCount
          oldestTime = item.lastAccessTime
        }
      }

      if (!lruKey) return
      this.cache.delete(lruKey)
    }
  }
}

export class RemoteVueSourceCache {
  private hit = 0
  private miss = 0
  private expired = 0

  constructor(private ttl = DEFAULT_TTL) {}

  get(key: string): RemoteVueSourceCacheValue | null {
    if (typeof window === 'undefined') return null

    const raw = window.localStorage.getItem(this.getStorageKey(key))
    if (!raw) {
      this.miss++
      return null
    }

    try {
      const item = JSON.parse(raw) as RemoteVueCacheItem<RemoteVueSourceCacheValue>
      if (Date.now() - item.timestamp > item.ttl) {
        this.expired++
        this.delete(key)
        return null
      }

      item.accessCount++
      item.lastAccessTime = Date.now()
      window.localStorage.setItem(this.getStorageKey(key), JSON.stringify(item))
      this.hit++
      return item.value
    } catch {
      this.delete(key)
      this.miss++
      return null
    }
  }

  set(
    key: string,
    value: RemoteVueSourceCacheValue,
    options: { ttl?: number; plugin?: string; url?: string } = {}
  ) {
    if (typeof window === 'undefined') return

    const now = Date.now()
    const item: RemoteVueCacheItem<RemoteVueSourceCacheValue> = {
      key,
      value,
      timestamp: now,
      ttl: options.ttl || this.ttl,
      plugin: options.plugin,
      url: options.url,
      accessCount: 1,
      lastAccessTime: now
    }

    try {
      window.localStorage.setItem(this.getStorageKey(key), JSON.stringify(item))
    } catch {
      this.cleanupExpired()
    }
  }

  delete(key: string) {
    if (typeof window === 'undefined') return
    window.localStorage.removeItem(this.getStorageKey(key))
  }

  clear() {
    if (typeof window === 'undefined') return

    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(STORAGE_PREFIX))
      .forEach((key) => window.localStorage.removeItem(key))
  }

  clearByPlugin(plugin: string) {
    if (typeof window === 'undefined') return 0

    let count = 0
    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(STORAGE_PREFIX))
      .forEach((storageKey) => {
        try {
          const item = JSON.parse(
            window.localStorage.getItem(storageKey) || '{}'
          ) as RemoteVueCacheItem<RemoteVueSourceCacheValue>
          if (item.plugin === plugin) {
            window.localStorage.removeItem(storageKey)
            count++
          }
        } catch {
          window.localStorage.removeItem(storageKey)
        }
      })

    return count
  }

  cleanupExpired() {
    if (typeof window === 'undefined') return 0

    let count = 0
    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(STORAGE_PREFIX))
      .forEach((storageKey) => {
        try {
          const item = JSON.parse(
            window.localStorage.getItem(storageKey) || '{}'
          ) as RemoteVueCacheItem<RemoteVueSourceCacheValue>
          if (Date.now() - item.timestamp > item.ttl) {
            window.localStorage.removeItem(storageKey)
            count++
          }
        } catch {
          window.localStorage.removeItem(storageKey)
          count++
        }
      })

    return count
  }

  getStats(memorySize: number): RemoteVueCacheStats {
    return {
      memorySize,
      persistentSize: this.getPersistentSize(),
      hit: this.hit,
      miss: this.miss,
      expired: this.expired
    }
  }

  private getPersistentSize() {
    if (typeof window === 'undefined') return 0
    return Object.keys(window.localStorage).filter((key) => key.startsWith(STORAGE_PREFIX)).length
  }

  private getStorageKey(key: string) {
    return `${STORAGE_PREFIX}${key}`
  }
}
