import { remoteVueLoader } from './index'
import type { RemoteVueComponentMeta, RemoteVueLoadOptions } from '@/types/remote-vue'

interface PreloadOptions extends RemoteVueLoadOptions {
  concurrency?: number
}

type NetworkConnection = {
  saveData?: boolean
  effectiveType?: string
}

const getConnection = () => {
  if (typeof navigator === 'undefined') return null
  return (navigator as Navigator & { connection?: NetworkConnection }).connection
}

const shouldSkipPreload = () => {
  const connection = getConnection()
  return Boolean(
    connection?.saveData ||
      (connection?.effectiveType && ['slow-2g', '2g'].includes(connection.effectiveType))
  )
}

const runWhenIdle = (callback: () => void) => {
  if (typeof globalThis.window === 'undefined') return

  const currentWindow = globalThis.window
  if (typeof currentWindow.requestIdleCallback === 'function') {
    currentWindow.requestIdleCallback(callback, { timeout: 2000 })
    return
  }

  currentWindow.setTimeout(callback, 1500)
}

export const preloadRemoteVueList = (
  list: RemoteVueComponentMeta[],
  options: PreloadOptions = {}
) => {
  if (!list.length || shouldSkipPreload()) return

  const queue = list.filter((item) => item.preload)
  if (!queue.length) return

  runWhenIdle(() => {
    const concurrency = Math.max(1, options.concurrency || 2)
    let cursor = 0
    let running = 0

    const runNext = () => {
      while (running < concurrency && cursor < queue.length) {
        const item = queue[cursor++]
        running++
        remoteVueLoader
          .preload(item, options)
          .catch((error) => {
            if (import.meta.env.DEV) {
              console.warn('[RemoteVue] preload failed:', item.url, error)
            }
          })
          .finally(() => {
            running--
            runNext()
          })
      }
    }

    runNext()
  })
}
