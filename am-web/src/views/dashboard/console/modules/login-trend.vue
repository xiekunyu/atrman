<!-- 登录趋势：近 N 天登录成功/失败走势 -->
<template>
  <div class="art-card h-100 p-5 mb-5 max-sm:mb-4">
    <div class="art-card-header">
      <div class="title">
        <h4>登录趋势</h4>
        <p>近{{ range }}天登录成功 / 失败走势</p>
      </div>
      <ElRadioGroup v-model="range" size="small" @change="loadTrend">
        <ElRadioButton :value="7" label="7天"></ElRadioButton>
        <ElRadioButton :value="14" label="14天"></ElRadioButton>
        <ElRadioButton :value="30" label="30天"></ElRadioButton>
      </ElRadioGroup>
    </div>

    <ArtLineChart
      v-loading="loading"
      height="calc(100% - 128px)"
      :data="seriesData"
      :xAxisData="xAxisLabels"
      :showLegend="true"
      :showAreaColor="true"
      :showAxisLine="false"
    />

    <div class="flex-b mt-2">
      <div class="flex-1">
        <p class="text-2xl text-g-900">{{ totalSuccess }}</p>
        <p class="text-xs text-g-500">累计成功</p>
      </div>
      <div class="flex-1">
        <p class="text-2xl text-g-900">{{ totalFail }}</p>
        <p class="text-xs text-g-500">累计失败</p>
      </div>
      <div class="flex-1">
        <p class="text-2xl text-g-900">{{ peakLabel }}</p>
        <p class="text-xs text-g-500">登录高峰日</p>
      </div>
      <div class="flex-1">
        <p class="text-2xl text-g-900">{{ activeDays }}</p>
        <p class="text-xs text-g-500">活跃天数</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import dashboardApi, { type LoginTrendItem } from '@/api/system/dashboard'
  import type { LineDataItem } from '@/types/component/chart'

  const loading = ref(false)
  const range = ref(30)
  const trend = ref<LoginTrendItem[]>([])

  const xAxisLabels = computed(() => trend.value.map((item) => item.date))

  const seriesData = computed<LineDataItem[]>(() => [
    {
      name: '成功',
      data: trend.value.map((item) => item.success)
    },
    {
      name: '失败',
      data: trend.value.map((item) => item.fail)
    }
  ])

  const totalSuccess = computed(() => trend.value.reduce((sum, item) => sum + item.success, 0))
  const totalFail = computed(() => trend.value.reduce((sum, item) => sum + item.fail, 0))

  const peakLabel = computed(() => {
    if (!trend.value.length) return '-'
    const peak = trend.value.reduce((max, item) => (item.success > max.success ? item : max))
    return peak.success > 0 ? peak.date : '-'
  })

  const activeDays = computed(() => trend.value.filter((item) => item.success > 0).length)

  const loadTrend = async () => {
    loading.value = true
    try {
      const result = await dashboardApi.loginTrend({ days: range.value })
      trend.value = Array.isArray(result) ? result : []
    } catch {
      // 错误提示由 HTTP 拦截器统一处理
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    loadTrend()
  })
</script>

<style lang="scss" scoped>
  .art-card {
    :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
      color: var(--el-color-primary) !important;
      background: transparent !important;
      border-color: var(--el-color-primary) !important;
      box-shadow: none !important;
    }
  }
</style>
