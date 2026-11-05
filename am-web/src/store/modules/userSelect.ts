import { defineStore } from 'pinia'
import userApi from '@/api/system/user'

export const useUserSelectStore = defineStore('userSelect', ()=>{
    const keyword = ref('')
    const userList = ref<Api.Auth.UserInfo[]>([])
    const total = ref(0)
    const pageSize = ref(1)
    const limit = ref(20)
    const loading = ref(false)
    const noMore = ref(false)

    const resetUserData = () => {
      keyword.value = ''
      userList.value = []
      total.value = 0
      pageSize.value = 1
      noMore.value = false
      loading.value = false
    }
    const searchUser = async (keywords: string) => {
      keyword.value = keywords
      pageSize.value = 1
      userList.value = []
      noMore.value = false
      loading.value = false
      await loadUserPage(1)
    }
    const loadUserPage = async (targetPage?: number) => {
      // 防并发：正在请求直接return
      if (loading.value || noMore.value) return
      const page = targetPage ?? pageSize.value
      const params: any = {
        current: page,
        size: limit.value,
        keywords: keyword.value
      }
      try {
        loading.value = true
        const res = await userApi.list(params) as any

        const newList = res?.data || []

        if (page === 1) {
          userList.value = newList
        } else {
          userList.value = [...userList.value, ...newList]
        }

        total.value = res?.count || 0
        // 更新当前页码
        pageSize.value = page
        // 判断是否无更多
        noMore.value = userList.value.length >= total.value
      } catch (error) {
        console.error('加载用户失败：', error)
      } finally {
        loading.value = false
      }
    }

    const loadNextPage = async () => {
      if (loading.value || noMore.value) return
      await loadUserPage(pageSize.value + 1)
    }

  return {
    keyword,
    userList,
    total,
    limit,
    pageSize,
    loading,
    noMore,
    resetUserData,
    searchUser,
    loadUserPage,
    loadNextPage
  }
}, {
  persist: {
    key: 'userSelect',
    storage: localStorage
  }
})
