<!-- 消息待办：当前用户未读站内信 -->
<template>
  <div class="art-card h-100 p-5 mb-5 max-sm:mb-4" v-loading="loading">
    <div class="art-card-header">
      <div class="title">
        <h4>消息待办</h4>
        <p>
          未读<span class="text-danger"> {{ unreadCount }} </span>
          <span v-if="unreadCount > total" class="text-g-500">（仅展示最近 {{ total }} 条）</span>
        </p>
      </div>
      <ElButton link type="primary" size="small" @click="goInbox">
        查看全部
        <ArtSvgIcon icon="ri:arrow-right-s-line" />
      </ElButton>
    </div>

    <div v-if="!loading && list.length === 0" class="h-[calc(100%-56px)] flex-cc">
      <ElEmpty description="没有待处理消息" :image-size="70" />
    </div>

    <div v-else class="h-[calc(100%-56px)] overflow-hidden">
      <ElScrollbar>
        <div
          class="flex-cb h-14 border-b border-g-300 text-sm last:border-b-0 c-p"
          v-for="item in list"
          :key="item.id"
          @click="goInbox"
        >
          <div class="flex-c min-w-0 mr-2">
            <span
              class="w-1.5 h-1.5 rounded-full bg-theme flex-shrink-0 mr-2"
              :class="{ 'opacity-0': item.is_read }"
            ></span>
            <span class="text-g-800 truncate">{{ item.title }}</span>
          </div>
          <span class="text-xs text-g-500 flex-shrink-0">
            {{ relativeTime(item.create_time) }}
          </span>
        </div>
      </ElScrollbar>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useRouter } from 'vue-router'
  import notifyApi from '@/api/notify'
  import { relativeTime } from './format'

  interface InboxBriefItem {
    id: number
    title: string
    type: string
    is_read: number
    create_time: number | string
  }

  const router = useRouter()
  const loading = ref(false)
  const list = ref<InboxBriefItem[]>([])
  const total = ref(0)
  const unreadCount = ref(0)

  const loadData = async () => {
    loading.value = true
    try {
      const result: any = await notifyApi.inbox.list({ is_read: 0, current: 1, size: 10 })
      list.value = Array.isArray(result?.items) ? result.items : []
      total.value = list.value.length
      unreadCount.value = Number(result?.unread_count ?? list.value.length)
    } catch {
      // 错误提示由 HTTP 拦截器统一处理
    } finally {
      loading.value = false
    }
  }

  const goInbox = () => {
    router.push('/notify/inbox')
  }

  onMounted(() => {
    loadData()
  })
</script>
