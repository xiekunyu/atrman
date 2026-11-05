import type { Component, ComputedRef, Ref } from 'vue'
import { remoteVueLoader } from '@/utils/remote-vue'
import type { RemoteVueComponentMeta, RemoteVueLoadOptions } from '@/types/remote-vue'

type RemoteVueSource = RemoteVueComponentMeta | string | null | undefined

type MaybeRefSource = RemoteVueSource | Ref<RemoteVueSource> | ComputedRef<RemoteVueSource>

interface UseRemoteVueOptions extends RemoteVueLoadOptions {
  name?: string
  immediate?: boolean
}

export function useRemoteVue(source: MaybeRefSource, options: UseRemoteVueOptions = {}) {
  const { immediate = true } = options
  const component = shallowRef<Component | null>(null)
  const loading = ref(false)
  const error = shallowRef<Error | null>(null)

  let requestId = 0
  let abortController: AbortController | null = null

  const sourceValue = computed(() => unref(source as Ref<RemoteVueSource>))

  const load = async (force = options.force) => {
    const currentSource = sourceValue.value
    const currentRequestId = ++requestId

    abortController?.abort()
    abortController = new AbortController()

    if (!currentSource) {
      component.value = null
      error.value = null
      loading.value = false
      return
    }

    loading.value = true
    error.value = null

    try {
      const loadedComponent = await remoteVueLoader.load(currentSource, {
        ...options,
        force,
        signal: abortController.signal
      })

      if (currentRequestId !== requestId) return
      component.value = loadedComponent
    } catch (err) {
      if (currentRequestId !== requestId) return
      if (err instanceof DOMException && err.name === 'AbortError') return

      component.value = null
      error.value = err instanceof Error ? err : new Error(String(err))
    } finally {
      if (currentRequestId === requestId) {
        loading.value = false
      }
    }
  }

  const reload = () => load(true)

  const clear = () => {
    requestId++
    abortController?.abort()
    component.value = null
    error.value = null
    loading.value = false
  }

  watch(
    sourceValue,
    () => {
      if (immediate) load()
    },
    { immediate }
  )

  onBeforeUnmount(() => {
    clear()
  })

  return {
    component,
    loading,
    error,
    reload,
    clear
  }
}
