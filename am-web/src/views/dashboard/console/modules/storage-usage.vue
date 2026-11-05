<!-- 存储用量：按文件类型统计容量占比 -->
<template>
  <div class="art-card h-100 p-5 mb-5 max-sm:mb-4 storage-usage" v-loading="loading">
    <div class="art-card-header">
      <div class="title">
        <h4>存储用量</h4>
        <p>{{ data?.total ?? 0 }} 个文件 · 共 {{ data?.size_human ?? '0 B' }}</p>
      </div>
      <ElButton text type="primary" :loading="loading" @click="loadData">
        <template #icon>
          <ArtSvgIcon icon="ri:refresh-line" />
        </template>
        刷新
      </ElButton>
    </div>

    <template v-if="hasChartData">
      <ArtRingChart
        class="box-border"
        height="calc(100% - 118px)"
        :data="chartData"
        :center-text="data?.size_human || '0 B'"
        :show-legend="true"
        legend-position="bottom"
        :radius="['46%', '68%']"
      />
      <div class="flex-b mt-1 px-1">
        <div class="flex-1">
          <p class="text-lg text-g-900">{{ topType?.name || '-' }}</p>
          <p class="text-xs text-g-500">占用最高类型</p>
        </div>
        <div class="flex-1">
          <p class="text-lg text-g-900">{{ topType?.size_human || '-' }}</p>
          <p class="text-xs text-g-500">占用容量</p>
        </div>
        <div class="flex-1">
          <p class="text-lg text-g-900">{{ topType?.count ?? '-' }}</p>
          <p class="text-xs text-g-500">文件数量</p>
        </div>
      </div>
    </template>
    <div v-else-if="!loading" class="storage-usage__empty">
      <span class="storage-usage__empty-icon">
        <ArtSvgIcon icon="ri:inbox-2-line" />
      </span>
      <p class="mt-3 text-sm text-g-600">{{
        (data?.total ?? 0) > 0 ? '暂无可统计的容量占用' : '暂无文件数据'
      }}</p>
      <p class="mt-1 text-xs text-g-400">
        {{ (data?.total ?? 0) > 0 ? '已上传的文件总容量为 0 B' : '上传文件后这里会展示容量分布' }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
  import dashboardApi, { type StorageUsage } from '@/api/system/dashboard'
  import type { PieDataItem } from '@/types/component/chart'

  const loading = ref(false)
  const data = ref<StorageUsage | null>(null)

  // 以 MB 为单位的容量占比（保留整数，避免浮点噪声）；0 字节文件不进环图
  const chartData = computed<PieDataItem[]>(() =>
    (data.value?.by_type ?? [])
      .filter((item) => item.size > 0)
      .map((item) => ({
        name: item.name,
        value: Math.max(1, Math.round(item.size / 1048576))
      }))
  )

  // 仅当存在容量占用时才展示环图，避免空环图 + 无意义统计
  const hasChartData = computed(() => chartData.value.length > 0)

  const topType = computed(() => {
    const types = data.value?.by_type ?? []
    if (!types.length) return null
    return types.reduce((max, item) => (item.size > max.size ? item : max))
  })

  const loadData = async () => {
    loading.value = true
    try {
      data.value = await dashboardApi.storage()
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
  .storage-usage__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: calc(100% - 56px);
    text-align: center;
  }

  .storage-usage__empty-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    font-size: 26px;
    color: var(--art-gray-400, #c0c4cc);
    background: var(--el-fill-color-light);
    border-radius: 16px;
  }
</style>
