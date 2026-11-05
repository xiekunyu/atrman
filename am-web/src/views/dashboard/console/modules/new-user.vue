<!-- 最近新增用户：基座最新注册用户列表 -->
<template>
  <div class="art-card p-5 h-100 overflow-hidden mb-5 max-sm:mb-4" v-loading="loading">
    <div class="art-card-header">
      <div class="title">
        <h4>新增用户</h4>
        <p>最近注册的 {{ list.length }} 位用户</p>
      </div>
      <ElButton text type="primary" :loading="loading" @click="loadData">
        <template #icon>
          <ArtSvgIcon icon="ri:refresh-line" />
        </template>
        刷新
      </ElButton>
    </div>

    <div v-if="!loading && list.length === 0" class="h-[calc(100%-56px)] flex-cc">
      <ElEmpty description="暂无用户" :image-size="70" />
    </div>

    <div v-else class="h-[calc(100%-56px)] overflow-hidden">
      <ElScrollbar>
        <div
          class="flex-cb h-16 border-b border-g-300 text-sm last:border-b-0"
          v-for="item in list"
          :key="item.id"
        >
          <div class="flex-c min-w-0">
            <span class="user-avatar">{{
              item.nickname?.charAt(0) || item.username?.charAt(0) || '用'
            }}</span>
            <div class="ml-2.5 min-w-0">
              <p class="truncate text-g-800 font-medium">
                {{ item.nickname || item.username }}
                <ElTag
                  v-if="item.account_type === 2"
                  class="ml-1 align-middle"
                  size="small"
                  type="info"
                  effect="plain"
                >
                  成员
                </ElTag>
              </p>
              <p class="truncate text-xs text-g-500 mt-0.5">@{{ item.username }}</p>
            </div>
          </div>
          <div class="text-right flex-shrink-0">
            <p class="text-xs text-g-600">{{ relativeTime(item.create_time) }}</p>
            <p class="text-xs text-g-400 mt-0.5">
              {{
                item.last_login_time ? `登录于 ${relativeTime(item.last_login_time)}` : '尚未登录'
              }}
            </p>
          </div>
        </div>
      </ElScrollbar>
    </div>
  </div>
</template>

<script setup lang="ts">
  import dashboardApi, { type NewUserItem } from '@/api/system/dashboard'
  import { relativeTime } from './format'

  const loading = ref(false)
  const list = ref<NewUserItem[]>([])

  const loadData = async () => {
    loading.value = true
    try {
      const result = await dashboardApi.newUsers({ limit: 8 })
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

<style lang="scss" scoped>
  .user-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    font-size: 15px;
    font-weight: 500;
    color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    border-radius: 10px;
  }
</style>
