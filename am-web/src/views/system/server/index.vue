<template>
  <div class="server-page" v-loading="loading">
    <div class="server-hero art-card">
      <div>
        <div class="flex items-center gap-2">
          <h3 class="server-title">服务器监控</h3>
          <ElTag :type="statusTagType" effect="light">{{ overview.statusText || '未知' }}</ElTag>
        </div>
        <p class="server-desc">聚合展示 CPU、内存、磁盘、网络、主机环境与 PHP 运行时状态。</p>
      </div>
      <ElSpace wrap>
        <ElTag type="info" effect="plain" size="large" class="text-sm">{{
          overview.updatedAt || '-'
        }}</ElTag>
        <ElButton type="primary" :loading="loading" @click="loadMonitor">
          <ArtSvgIcon icon="ri:refresh-line" class="mr-1" />
          刷新监控
        </ElButton>
      </ElSpace>
    </div>

    <ElRow :gutter="16" class="server-row">
      <ElCol v-for="item in summaryCards" :key="item.label" :xs="24" :sm="12" :lg="6">
        <ElCard shadow="never" class="metric-card">
          <div class="metric-card__content">
            <span class="metric-card__label">{{ item.label }}</span>
            <ElTooltip
              :content="String(item.tooltip || item.value)"
              placement="top"
              :disabled="!shouldShowTooltip(item.tooltip || item.value)"
            >
              <strong class="metric-card__value text-ellipsis">{{ item.value }}</strong>
            </ElTooltip>
            <span class="metric-card__desc text-ellipsis">{{ item.desc }}</span>
          </div>
          <div class="metric-card__icon" :class="item.className">
            <ArtSvgIcon :icon="item.icon" />
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="server-row">
      <ElCol v-for="item in indicatorCards" :key="item.label" :xs="24" :sm="12" :lg="6">
        <ElCard shadow="never" class="indicator-card">
          <div class="flex justify-between items-start">
            <div class="flex items-center gap-2">
              <span class="indicator-card__label">{{ item.label }}</span>
              <strong class="indicator-card__value">{{ item.value }}</strong>
            </div>
            <div class="indicator-card__icon" :class="item.className">
              <ArtSvgIcon :icon="item.icon" />
            </div>
          </div>
          <div class="indicator-progress">
            <div class="indicator-progress__track">
              <div
                class="indicator-progress__bar"
                :style="{ width: `${item.percent}%`, background: item.color }"
              />
            </div>
            <span class="indicator-progress__text">{{ item.percent }}%</span>
          </div>
          <div class="indicator-card__footer">
            <span>{{ item.desc }}</span>
            <span>{{ item.extra }}</span>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="server-row">
      <ElCol :xs="24" :lg="8">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>健康评分</h4>
                <p>结合 CPU、内存、磁盘和诊断结果综合评估。</p>
              </div>
              <ElTag :type="statusTagType">{{ overview.statusText || '未知' }}</ElTag>
            </div>
          </template>
          <div class="health-panel">
            <ElProgress
              type="dashboard"
              :percentage="healthScore"
              :color="healthColor"
              :stroke-width="20"
              :width="200"
            >
              <template #default>
                <div class="health-score">{{ healthScore }}</div>
                <div class="health-desc">综合评分 / 100</div>
              </template>
            </ElProgress>
            <p>{{ healthText }}</p>
          </div>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="16">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>运行环境</h4>
                <p>定位实例、系统版本与 PHP 运行状态。</p>
              </div>
            </div>
          </template>
          <ElRow :gutter="12">
            <ElCol v-for="item in runtimeItems" :key="item.label" :xs="24" :sm="12" :lg="8">
              <div class="info-item">
                <span>{{ item.label }}</span>
                <ElTooltip
                  :content="String(item.value)"
                  placement="top"
                  :disabled="!shouldShowTooltip(item.value)"
                >
                  <strong>{{ item.value }}</strong>
                </ElTooltip>
              </div>
            </ElCol>
          </ElRow>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="server-row">
      <ElCol :xs="24" :lg="14">
        <ElCard shadow="never" class="section-card">
          <template #header>
            <div class="section-title">
              <div>
                <h4>磁盘分区</h4>
                <p>按挂载点查看空间使用，优先关注高占用分区。</p>
              </div>
              <ElTag size="small" type="success">{{ diskItems.length }} 项</ElTag>
            </div>
          </template>
          <div class="disk-list">
            <div v-for="item in diskItems" :key="item.path" class="disk-item">
              <div class="disk-item__head">
                <div class="disk-item__info">
                  <ElTooltip
                    :content="String(item.mount || item.path)"
                    placement="top"
                    :disabled="!shouldShowTooltip(item.mount || item.path)"
                  >
                    <strong>{{ item.mount || item.path }}</strong>
                  </ElTooltip>
                  <ElTooltip
                    :content="String(item.device || item.filesystem || item.path)"
                    placement="top"
                    :disabled="!shouldShowTooltip(item.device || item.filesystem || item.path)"
                  >
                    <p>{{ item.device || item.filesystem || item.path }}</p>
                  </ElTooltip>
                </div>
                <span>{{ item.usedHuman }} / {{ item.totalHuman }}</span>
              </div>
              <div class="indicator-progress">
                <div class="indicator-progress__track">
                  <div
                    class="indicator-progress__bar"
                    :style="{
                      width: `${displayPercent(item.usedPercent)}%`,
                      background: '#f59e0b'
                    }"
                  />
                </div>
                <span class="indicator-progress__text"
                  >{{ displayPercent(item.usedPercent) }}%</span
                >
              </div>
              <div class="disk-item__footer">
                <span>可用 {{ item.freeHuman || '-' }}</span>
                <span>{{ item.filesystem || '-' }}</span>
              </div>
            </div>
          </div>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="10">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>网络接口</h4>
                <p>展示网卡累计接收、发送和错误丢包情况。</p>
              </div>
              <ElTag size="small">{{ networkInterfaces.length }} Up</ElTag>
            </div>
          </template>
          <ElRow :gutter="12" class="mb-3">
            <ElCol :span="12">
              <div class="info-item">
                <span>累计接收</span>
                <strong>{{ network.totalRxHuman || '0 B' }}</strong>
              </div>
            </ElCol>
            <ElCol :span="12">
              <div class="info-item">
                <span>累计发送</span>
                <strong>{{ network.totalTxHuman || '0 B' }}</strong>
              </div>
            </ElCol>
          </ElRow>
          <div class="network-list">
            <div v-for="item in networkInterfaces" :key="item.name" class="network-item">
              <div class="network-item__info">
                <ElTooltip
                  :content="item.name"
                  placement="top"
                  :disabled="!shouldShowTooltip(item.name)"
                >
                  <strong>{{ item.name }}</strong>
                </ElTooltip>
                <p>收 {{ item.rxHuman }} / 发 {{ item.txHuman }}</p>
              </div>
              <ElTag size="small" :type="item.rxErrors || item.txErrors ? 'warning' : 'success'">
                错误 {{ (item.rxErrors || 0) + (item.txErrors || 0) }}
              </ElTag>
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import serverApi from '@/api/system/server'

  defineOptions({ name: 'SystemServer' })

  interface DiskItem {
    path: string
    mount?: string
    device?: string
    filesystem?: string
    usedPercent?: number | null
    usedHuman?: string
    totalHuman?: string
    freeHuman?: string
  }

  interface NetworkItem {
    name: string
    rxHuman?: string
    txHuman?: string
    rxErrors?: number
    txErrors?: number
  }

  const loading = ref(false)
  const overview = ref<Record<string, any>>({})
  const cpu = ref<Record<string, any>>({})
  const memory = ref<Record<string, any>>({})
  const disk = ref<Record<string, any>>({ items: [] })
  const network = ref<Record<string, any>>({ interfaces: [] })
  const host = ref<Record<string, any>>({})
  const php = ref<Record<string, any>>({})
  const processes = ref<Record<string, any>>({})
  const diagnostics = ref<Array<{ level: string }>>([])

  const statusTagType = computed(() => {
    if (overview.value.status === 'healthy') return 'success'
    if (overview.value.status === 'warning') return 'warning'
    if (overview.value.status === 'danger') return 'danger'
    return 'info'
  })

  const healthScore = computed(() => {
    const cpuScore = 100 - displayPercent(cpu.value.usagePercent)
    const memoryScore = 100 - displayPercent(memory.value.usedPercent)
    const diskScore = 100 - displayPercent(disk.value.usedPercent)
    const penalty =
      diagnostics.value.filter((item) => ['warning', 'danger', 'error'].includes(item.level))
        .length * 6
    return Math.max(0, Math.round((cpuScore + memoryScore + diskScore) / 3 - penalty))
  })

  const healthColor = computed(() => {
    if (healthScore.value >= 80) return '#10b981'
    if (healthScore.value >= 60) return '#f59e0b'
    return '#ef4444'
  })

  const healthText = computed(() => {
    if (healthScore.value >= 80) return '服务器资源处于健康状态，建议保持当前监控频率。'
    if (healthScore.value >= 60) return '存在部分压力，建议关注高占用资源和慢请求趋势。'
    return '资源压力较高，建议立即排查 CPU、内存或磁盘占用。'
  })

  const summaryCards = computed(() => [
    {
      label: '主机',
      value: overview.value.hostname || '-',
      desc: host.value.os || overview.value.platform || '-',
      tooltip: overview.value.hostname || '-',
      icon: 'ri:server-line',
      className: 'is-blue'
    },
    {
      label: '平台',
      value: overview.value.platform || '-',
      desc: host.value.arch || '-',
      tooltip: overview.value.platform || '-',
      icon: 'ri:terminal-window-line',
      className: 'is-green'
    },
    {
      label: '系统运行',
      value: overview.value.uptimeHuman || host.value.uptimeHuman || '-',
      desc: host.value.serverTime || '-',
      tooltip: overview.value.uptimeHuman || host.value.uptimeHuman || '-',
      icon: 'ri:time-line',
      className: 'is-cyan'
    },
    {
      label: 'PHP PID',
      value: processes.value.current?.pid ? `#${processes.value.current.pid}` : '-',
      desc: php.value.version ? `PHP ${php.value.version}` : '-',
      tooltip: processes.value.current?.pid ? `#${processes.value.current.pid}` : '-',
      icon: 'ri:code-box-line',
      className: 'is-orange'
    }
  ])

  const indicatorCards = computed(() => [
    {
      label: 'CPU 使用率',
      value: percentText(cpu.value.usagePercent),
      percent: displayPercent(cpu.value.usagePercent),
      desc: `${cpu.value.cores || 0} 核`,
      extra: cpu.value.loadAvg?.length ? `负载 ${cpu.value.loadAvg.join(' / ')}` : '负载 -',
      icon: 'ri:cpu-line',
      className: 'is-blue',
      color: '#3b82f6'
    },
    {
      label: '内存占用',
      value: percentText(memory.value.usedPercent),
      percent: displayPercent(memory.value.usedPercent),
      desc: `${memory.value.usedHuman || '-'} / ${memory.value.totalHuman || '-'}`,
      extra: `可用 ${memory.value.availableHuman || '-'}`,
      icon: 'ri:database-2-line',
      className: 'is-orange',
      color: '#f59e0b'
    },
    {
      label: '磁盘占用',
      value: percentText(disk.value.usedPercent),
      percent: displayPercent(disk.value.usedPercent),
      desc: `${disk.value.usedHuman || '-'} / ${disk.value.totalHuman || '-'}`,
      extra: `${diskItems.value.length} 个分区`,
      icon: 'ri:hard-drive-2-line',
      className: 'is-cyan',
      color: '#06b6d4'
    },
    {
      label: 'PHP 进程',
      value: displayValue(processes.value.php?.total),
      percent: displayPercent((processes.value.php?.total || 0) * 10),
      desc: `RSS ${processes.value.php?.memoryRssHuman || '-'}`,
      extra: `Webman ${processes.value.webman?.total || 0} 个`,
      icon: 'ri:terminal-box-line',
      className: 'is-green',
      color: '#10b981'
    }
  ])

  const runtimeItems = computed(() => [
    { label: '主机名', value: host.value.hostname || '-' },
    { label: '操作系统', value: host.value.os || '-' },
    { label: '系统架构', value: host.value.arch || '-' },
    { label: 'PHP 版本', value: php.value.version || '-' },
    { label: 'PHP SAPI', value: php.value.sapi || '-' },
    { label: '系统运行时长', value: host.value.uptimeHuman || '-' },
    { label: '内核版本', value: host.value.kernel || '-' },
    { label: '内存限制', value: php.value.memoryLimit || '-' },
    { label: '扩展数量', value: displayValue(php.value.extensionsCount) }
  ])

  const diskItems = computed<DiskItem[]>(() => disk.value.items || [])
  const networkInterfaces = computed<NetworkItem[]>(() => network.value.interfaces || [])
  onMounted(() => {
    loadMonitor()
  })

  async function loadMonitor() {
    loading.value = true
    try {
      const data = (await serverApi.monitor()) as Record<string, any>
      overview.value = data.overview || {}
      cpu.value = data.cpu || {}
      memory.value = data.memory || {}
      disk.value = data.disk || { items: [] }
      network.value = data.network || { interfaces: [] }
      host.value = data.host || {}
      php.value = data.php || {}
      processes.value = data.processes || {}
      diagnostics.value = data.diagnostics || []
    } finally {
      loading.value = false
    }
  }

  function displayPercent(value: unknown) {
    const num = Number(value || 0)
    return Math.max(0, Math.min(100, Number.isFinite(num) ? Math.round(num) : 0))
  }

  function percentText(value: unknown) {
    if (value === null || value === undefined || value === '') return '-'
    return `${displayPercent(value)}%`
  }

  function displayValue(value: unknown, fallback = '-') {
    if (value === null || value === undefined || value === '') return fallback
    return String(value)
  }

  function shouldShowTooltip(value: unknown) {
    return String(value ?? '').length > 12
  }
</script>

<style scoped lang="scss">
  .server-page {
    min-height: 100%;
    padding-bottom: 16px;
  }

  .server-row {
    row-gap: 16px;
    margin-top: 16px;
  }

  .server-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 22px 24px;
    border: 1px solid var(--el-border-color-light);
    border-radius: var(--custom-radius);
  }

  .server-title {
    margin: 0;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.02em;
  }

  .server-desc {
    margin: 8px 0 0;
    font-size: 13px;
  }

  .metric-card,
  .indicator-card,
  .section-card {
    overflow: hidden;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: var(--custom-radius);
    transition: border-color 0.22s ease;

    &:hover {
      border-color: var(--el-color-primary-light-5);
    }
  }

  .metric-card {
    min-height: 112px;

    :deep(.el-card__body) {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 100%;
    }
  }

  .metric-card__content,
  .indicator-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .metric-card__label,
  .indicator-card__label {
    color: var(--art-gray-600);
    font-size: 13px;
  }

  .metric-card__value,
  .indicator-card__value {
    max-width: 100%;
    overflow: hidden;
    color: var(--art-gray-900);
    font-size: 26px;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.02em;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .metric-card__desc,
  .indicator-card__footer {
    max-width: 100%;
    overflow: hidden;
    color: var(--art-gray-500);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .metric-card__icon,
  .indicator-card__icon,
  .diagnostic-item__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 14px;
  }

  .metric-card__icon {
    width: 50px;
    height: 50px;
    font-size: 23px;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 42%);
  }

  .indicator-card__icon {
    width: 38px;
    height: 38px;
    font-size: 18px;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 42%);
  }

  .indicator-progress {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 10px;
    margin: 15px 0;
  }

  .indicator-progress__track {
    position: relative;
    height: 8px;
    overflow: hidden;
    background: rgb(148 163 184 / 13%);
    border: 1px solid rgb(148 163 184 / 10%);
    border-radius: 999px;
  }

  .indicator-progress__bar {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    min-width: 6px;
    overflow: hidden;
    border-radius: inherit;
    box-shadow: 0 0 14px currentcolor;
    transition: width 0.45s ease;

    &::after {
      position: absolute;
      inset: 0;
      content: '';
      background: linear-gradient(90deg, transparent, rgb(255 255 255 / 42%), transparent);
      transform: translateX(-100%);
      animation: server-progress-light 1.8s ease-in-out infinite;
    }
  }

  .indicator-progress__text {
    min-width: 38px;
    color: var(--art-gray-600);
    font-size: 12px;
    font-weight: 600;
    text-align: right;
  }

  .indicator-card__footer,
  .disk-item__head,
  .disk-item__footer,
  .process-item,
  .network-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  @keyframes server-progress-light {
    0% {
      transform: translateX(-100%);
    }

    65%,
    100% {
      transform: translateX(100%);
    }
  }

  .is-blue {
    color: #3b82f6;
    background: linear-gradient(135deg, rgb(59 130 246 / 18%), rgb(59 130 246 / 7%));
  }

  .is-green {
    color: #10b981;
    background: linear-gradient(135deg, rgb(16 185 129 / 18%), rgb(16 185 129 / 7%));
  }

  .is-cyan {
    color: #06b6d4;
    background: linear-gradient(135deg, rgb(6 182 212 / 18%), rgb(6 182 212 / 7%));
  }

  .is-orange {
    color: #f59e0b;
    background: linear-gradient(135deg, rgb(245 158 11 / 20%), rgb(245 158 11 / 8%));
  }

  .section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;

    h4 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
    }

    p {
      margin: 6px 0 0;
      color: var(--art-gray-600);
      font-size: 12px;
    }
  }

  .info-item {
    min-width: 0;
    padding: 14px;
    margin-bottom: 12px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;

    span {
      display: block;
      color: var(--art-gray-600);
      font-size: 12px;
    }

    strong {
      display: block;
      max-width: 100%;
      margin-top: 8px;
      overflow: hidden;
      font-size: 14px;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .health-panel {
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 12px;
    padding: 10px 0 4px;
    text-align: center;

    p {
      margin: 0;
      color: var(--art-gray-600);
      font-size: 13px;
      line-height: 1.6;
    }
  }

  .health-score {
    color: var(--art-gray-900);
    font-size: 28px;
    font-weight: 700;
  }

  .health-desc {
    color: var(--art-gray-500);
    font-size: 12px;
  }

  .disk-list,
  .network-list,
  .process-list,
  .diagnostic-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .disk-item,
  .network-item,
  .process-item,
  .diagnostic-item {
    padding: 12px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;
  }

  .disk-item__head {
    align-items: flex-start;

    .disk-item__info,
    .network-item__info {
      min-width: 0;
    }

    strong,
    p {
      display: block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    p {
      margin: 4px 0 0;
      color: var(--art-gray-500);
      font-size: 12px;
    }

    span {
      color: var(--art-gray-600);
      font-size: 12px;
      white-space: nowrap;
    }
  }

  .disk-item__footer {
    margin-top: 6px;
    color: var(--art-gray-500);
    font-size: 12px;
  }

  .network-item__info {
    min-width: 0;

    strong,
    p {
      display: block;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .network-item p,
  .process-item p,
  .diagnostic-item p {
    margin: 4px 0 0;
    color: var(--art-gray-600);
    font-size: 12px;
    line-height: 1.5;
  }

  .process-item__main {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-width: 0;

    p {
      max-width: 520px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .process-item__meta {
    display: flex;
    align-items: flex-end;
    flex-direction: column;
    flex-shrink: 0;
    gap: 4px;
    color: var(--art-gray-500);
    font-size: 12px;
  }

  .diagnostic-item {
    display: flex;
    gap: 12px;
  }

  .diagnostic-item__icon {
    width: 34px;
    height: 34px;
    font-size: 17px;

    &.is-success {
      color: var(--el-color-success);
      background: var(--el-color-success-light-9);
    }

    &.is-warning {
      color: var(--el-color-warning);
      background: var(--el-color-warning-light-9);
    }

    &.is-danger,
    &.is-error {
      color: var(--el-color-danger);
      background: var(--el-color-danger-light-9);
    }

    &.is-info {
      color: var(--el-color-info);
      background: var(--el-color-info-light-9);
    }
  }

  :global(html.dark) .server-page {
    .server-hero {
      background: var(--el-color-primary-dark-2);
      border-color: var(--el-border-color-light);
    }
  }

  @media (max-width: 768px) {
    .server-hero {
      align-items: flex-start;
      flex-direction: column;
    }

    .process-item {
      align-items: flex-start;
      flex-direction: column;
    }
  }
</style>
