<!-- 系统健康：基座服务器资源状态 -->
<template>
  <div class="art-card h-100 p-5 mb-5 max-sm:mb-4" v-loading="loading">
    <div class="art-card-header">
      <div class="title min-w-0">
        <h4 class="flex-c">
          系统健康
          <ElTag v-if="data" class="ml-2" size="small" :type="statusTagType" effect="light">
            {{ data.overview.statusText || '-' }}
          </ElTag>
        </h4>
        <p class="truncate" :title="hostLine">{{ hostLine }}</p>
      </div>
      <ElButton text type="primary" class="flex-shrink-0" :loading="loading" @click="loadData">
        <template #icon>
          <ArtSvgIcon icon="ri:refresh-line" />
        </template>
        刷新
      </ElButton>
    </div>

    <div class="mt-3 space-y-3">
      <div v-for="item in progressItems" :key="item.label">
        <div class="flex-cb text-xs mb-1">
          <span class="text-g-600">{{ item.label }}</span>
          <span class="text-g-800 font-medium tabular-nums">{{ formatPercent(item.value) }}</span>
        </div>
        <ElProgress
          :percentage="item.value ?? 0"
          :stroke-width="6"
          :show-text="false"
          :color="progressColor(item.value)"
        />
      </div>
    </div>

    <div class="grid grid-cols-3 gap-2 mt-4">
      <div v-for="item in metaItems" :key="item.label" class="server-meta">
        <p class="text-xs text-g-500">{{ item.label }}</p>
        <p class="mt-0.5 text-[13px] leading-5 font-medium text-g-800 truncate" :title="item.value">
          {{ item.value }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import dashboardApi, { type ServerHealth } from '@/api/system/dashboard'

  const REFRESH_INTERVAL = 60 * 1000

  const loading = ref(false)
  const data = ref<ServerHealth | null>(null)
  let timer: ReturnType<typeof setInterval> | null = null

  const statusTagType = computed(() => {
    const status = data.value?.overview?.status
    if (status === 'danger') return 'danger' as const
    if (status === 'warning') return 'warning' as const
    return 'success' as const
  })

  const hostLine = computed(
    () =>
      `${data.value?.overview.hostname || '服务器'} · 运行 ${data.value?.overview.uptimeHuman || '-'}`
  )

  const progressItems = computed(() => [
    { label: 'CPU 使用率', value: data.value?.cpu.usagePercent ?? null },
    { label: '内存使用率', value: data.value?.memory.usedPercent ?? null },
    { label: '磁盘使用率', value: data.value?.disk.usedPercent ?? null }
  ])

  const loadText = computed(() => {
    const load = data.value?.cpu.loadAvg ?? []
    return load.length ? String(load[0]) : '-'
  })

  const metaItems = computed(() => [
    { label: 'CPU', value: data.value ? `${data.value.cpu.cores} 核` : '-' },
    { label: '负载(1m)', value: loadText.value },
    { label: '内存占用', value: data.value?.memory.usedHuman ?? '-' },
    { label: '磁盘可用', value: data.value?.disk.freeHuman ?? '-' },
    { label: 'PHP 进程', value: data.value ? `${data.value.processes.php} 个` : '-' },
    { label: 'PHP 版本', value: data.value?.php.version || '-' }
  ])

  /** 百分比：整数不带小数，非整数保留 1 位；无数据显示 -- */
  const formatPercent = (value: number | null) => {
    if (value === null || Number.isNaN(value)) return '--'
    const rounded = Math.round(value * 10) / 10
    return `${Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1)}%`
  }

  const progressColor = (value: number | null) => {
    if (value === null) return 'var(--el-color-info)'
    if (value >= 85) return 'var(--el-color-danger)'
    if (value >= 70) return 'var(--el-color-warning)'
    return 'var(--el-color-success)'
  }

  const loadData = async () => {
    loading.value = true
    try {
      data.value = await dashboardApi.serverHealth()
    } catch {
      // 错误提示由 HTTP 拦截器统一处理
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    loadData()
    timer = setInterval(loadData, REFRESH_INTERVAL)
  })

  onUnmounted(() => {
    if (timer) clearInterval(timer)
  })
</script>

<style lang="scss" scoped>
  .server-meta {
    padding: 8px 4px;
    text-align: center;
    background: var(--el-fill-color-lighter);
    border-radius: 10px;
  }
</style>
