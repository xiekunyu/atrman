import { defineStore } from 'pinia'
import request from '@/utils/http'
import remoteApi from '@/api/system/remote'
import { findTreeItems, findListItems } from '@/utils/custom/index'

type RemoteItem = Api.SystemManage.RemoteItem
export const useRemoteDataStore = defineStore(
  'remoteData',
  () => {
    const dataSourceConfigs = ref<Record<string, RemoteItem>>({})
    const remoteList = ref<any>([])
    const cachedOptions = ref<Record<string, Api.SystemManage.BaseOption[]>>({})
    const treeProps = ref<Record<string, any>>({})
    
    // 初始化数据源配置
    const initConfigs = async () => {
      remoteList.value = await remoteApi.list({list_type:'all'})
      remoteList.value.forEach((c: RemoteItem) => {
        if (c.code) {
          dataSourceConfigs.value[c.code] = c
        }
      })
    }

    // 核心：获取数据 + 缓存 + 搜索 + 分页
      const fetchOptions = async (code: string, params: any = {}) => {
      const config = dataSourceConfigs.value[code]
      if (!config) {
        return [];
      }
      // 如果是树结构，需要特殊处理
      if (config?.type === 2) {
          treeProps.value = {
            children: config?.children_field || 'children',
            label: config?.label_field || 'label',
            value: config?.value_field || 'value'
          }
          params.tree=true
      }
      const cacheKey = `${code}_${JSON.stringify(params)}`
      if (cachedOptions.value[cacheKey] && cachedOptions.value[cacheKey].length>0){
        return cachedOptions.value[cacheKey];
      }

      try {
        const data = await requestData(config, params);
        let list=[];
        if (config?.type !== 2) {
          list = data?.map((item: any) => ({
            label: item[config.label_field],
            value: item[config.value_field]
          }))
        }else{
          list = data || []
        }
        if(list.length>0){
          cachedOptions.value[cacheKey] = list
        }
        return list
      } catch (e) {
        return []
      } finally {
      }
    }

    // 查找缓存中的选项
    const findOption = async (code: string, value: string | number | Array<string | number>) => {
      const config = dataSourceConfigs.value[code]
      if (!config) return []
      const list=await fetchOptions(code,{})
      if(!list) return null
      if(config?.type === 2){
        return findTreeItems(list, value)
      }else{
        return findListItems(list, value, config.valueField)
      }
    }
    
    const requestData = (config: RemoteItem, params: Record<string, any> = {}) => {
      const requestData={
        url: config.api_url,
        params: {
          ...config.params,
          ...params
        },
        headers: {
          ...config.header
        }
      };
      if(config?.method === 2){
        return request.post<any>(requestData)
      }else{
        return request.get<any>(requestData)
      }
    }

    // 按编码清除指定数据源的缓存
    const clearCacheByCode = (code: string) => {
      const keys = Object.keys(cachedOptions.value)
      keys.forEach(key => {
        if (key.startsWith(`${code}_`)) {
          delete cachedOptions.value[key]
        }
      })
    }

    // 刷新缓存
    const refreshCache = () => {
      dataSourceConfigs.value={}
      cachedOptions.value={}
      treeProps.value={}
      remoteList.value=[]
      initConfigs()
    }

    return {
      remoteList,
      cachedOptions,
      treeProps,
      findOption,
      fetchOptions,
      refreshCache,
      clearCacheByCode,
      initConfigs
    }
  },
  {
    persist: {
      key: 'remoteData',
      storage: localStorage
    }
  }
)
