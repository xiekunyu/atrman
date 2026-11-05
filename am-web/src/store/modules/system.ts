import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchGetSystemInfo } from '@/api/system-manage'
import { mergeSystemInfo } from '@/config'

export const useSystemStore = defineStore('systemStore', () => {
  const initialized = ref(false)
  const loading = ref(false)
  const systemInfo = ref<Api.SystemManage.SystemInfo>({ website: {} })

  const setSystemInfo = (data: Api.SystemManage.SystemInfo) => {
    systemInfo.value = data || { website: {} }
    mergeSystemInfo(systemInfo.value)
    initialized.value = true
  }

  const refreshSystemInfo = async () => {
    loading.value = true
    try {
      const data = await fetchGetSystemInfo()
      setSystemInfo(data || { website: {} })
      return systemInfo.value
    } catch (e) {
      initialized.value = false
      throw e
    } finally {
      loading.value = false
    }
  }

  const ensureSystemInfo = async (force = false) => {
    if (!force && initialized.value) {
      return systemInfo.value
    }
    return refreshSystemInfo()
  }

  return {
    initialized,
    loading,
    systemInfo,
    setSystemInfo,
    refreshSystemInfo,
    ensureSystemInfo
  }
})
