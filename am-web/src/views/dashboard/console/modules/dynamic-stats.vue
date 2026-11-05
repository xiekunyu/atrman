<!-- 系统动态：最近的业务操作记录 -->
<template>
  <div class="art-card h-100 p-5 mb-5 max-sm:mb-4" v-loading="loading">
    <div class="art-card-header">
      <div class="title">
        <h4>系统动态</h4>
        <p>最近 {{ list.length }} 条业务操作</p>
      </div>
      <ElButton text type="primary" :loading="loading" @click="loadData">
        <template #icon>
          <ArtSvgIcon icon="ri:refresh-line" />
        </template>
        刷新
      </ElButton>
    </div>

    <div v-if="!loading && list.length === 0" class="h-[calc(100%-56px)] flex-cc">
      <ElEmpty description="暂无操作记录" :image-size="70" />
    </div>

    <div v-else class="h-[calc(100%-56px)] mt-2 overflow-hidden">
      <ElScrollbar>
        <div
          class="flex-cb h-14 border-b border-g-300 text-sm last:border-b-0"
          v-for="item in list"
          :key="item.id"
        >
          <div class="flex-c min-w-0">
            <span class="text-g-800 font-medium flex-shrink-0">{{ item.username || '系统' }}</span>
            <span class="mx-2 text-g-600 truncate">{{ item.business_name }}</span>
            <span v-if="item.ip_location" class="text-xs text-g-400 truncate">
              {{ item.ip_location }}
            </span>
          </div>
          <span class="text-xs text-g-500 flex-shrink-0 ml-2">
            {{ relativeTime(item.create_time) }}
          </span>
        </div>
      </ElScrollbar>
    </div>
  </div>
</template>

<script setup lang="ts">
  import dashboardApi, { type ActivityItem } from '@/api/system/dashboard'
  import { relativeTime } from './format'

  const loading = ref(false)
  const list = ref<ActivityItem[]>([])

  const loadData = async () => {
    loading.value = true
    try {
      const result = await dashboardApi.activities({ limit: 12 })
      list.value = Array.isArray(result) ? result : []
    } catch {
      // 错误提示由 HTTP 拦截器统一处理
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    loadData()
  })
</script>
