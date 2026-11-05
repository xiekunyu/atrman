import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchGetDictList } from '@/api/system-manage'
import dictApi from '@/api/system/dict'

/**
 * 字典状态管理模块
 * - 负责全局字典的加载、缓存、查询
 * - 建议在菜单加载完成后调用 ensureLoaded() 进行初始化
 */
export const useDictStore = defineStore(
  'dictStore',
  () => {
    /** 字典是否已初始化加载 */
    const initialized = ref(false)
    /** 原始字典列表 */
    const dictList = ref<Api.SystemManage.DictData>()

    const dictData =ref<any>({})
    const refresh = async () => {
      try {
        const list = await fetchGetDictList()
        dictList.value = list
        initialized.value = true
      } catch (e) {
        // 保持状态一致：加载失败也标记为未初始化
        initialized.value = false
        throw e
      }
    }

    const getByCode = (code: any): Api.SystemManage.DictItem[] => {
      return dictList.value?.[code] || []
    }

    const getDataByValue = (code: any, value: any): Api.SystemManage.DictItem | undefined => {
      const dict = getByCode(code)
      if (!dict) return undefined

      const item = dict.find((item) => item.value == value)
      return item || undefined
    }

    const setDictList = (list: Api.SystemManage.DictData) => {
      dictList.value = list
      initialized.value = true
    }

    const fetchDictData = async () => {
      const data=await dictApi.list({})
      dictData.value=data
    }

    return {
      initialized,
      dictList,
      dictData,
      fetchDictData,
      refresh,
      setDictList,
      getByCode,
      getDataByValue
    }
  },
  {
    persist: {
      key: 'dict',
      storage: localStorage
    }
  }
)
