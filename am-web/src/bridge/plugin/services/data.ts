import configApi from '@/api/system/config'
import { useDictStore } from '@/store/modules/dict'
import { useRemoteDataStore } from '@/store/modules/remoteData'

const ensureDictLoaded = async () => {
  const dictStore = useDictStore()
  if (!dictStore.initialized) {
    await dictStore.refresh()
  }
  return dictStore
}

const ensureRemoteLoaded = async () => {
  const remoteDataStore = useRemoteDataStore()
  if (!remoteDataStore.remoteList?.length) {
    await remoteDataStore.initConfigs()
  }
  return remoteDataStore
}

export const dataBridgeService = {
  async getDict(params: { code?: string } = {}) {
    const dictStore = await ensureDictLoaded()
    if (!params.code) return dictStore.dictList || {}
    return dictStore.getByCode(params.code)
  },

  async getConfig(params: Record<string, any> = {}) {
    return configApi.getConfig(params)
  },

  async getRemoteOptions(params: { code?: string; params?: Record<string, any> } = {}) {
    if (!params.code) return []
    const remoteDataStore = await ensureRemoteLoaded()
    return remoteDataStore.fetchOptions(params.code, params.params || {})
  }
}
