import { defineStore } from 'pinia'
// 你的用户分页接口
import fileApi from '@/api/system/file'

export const useFileStore = defineStore('file', ()=>{
    const folderList = ref<any[]>([])
    const folderTotal = ref<number>(0)

    const loadFolder = async () => {
      const res = await fileApi.list({
        tree: true
      })
      folderList.value = res?.data || []
      folderTotal.value = res?.total || 0
    }

  return {
    folderList,
    folderTotal,
    loadFolder
  }
}, {
  persist: {
    key: 'file',
    storage: localStorage
  }
})
