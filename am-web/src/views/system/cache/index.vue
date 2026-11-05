<template>
  <div class="am-dashboard-page" v-loading="loading">
    <div class="am-dashboard-hero art-card">
      <div>
        <div class="flex items-center gap-2">
          <h3 class="am-dashboard-hero__title">缓存监控</h3>
          <ElTag :type="statusTagType" effect="light">{{ overview.statusText || '未知' }}</ElTag>
        </div>
        <p class="am-dashboard-hero__desc"
          >Redis/缓存监控与业务缓存安全清理，页面展示实时指标并支持按业务命名空间清理。</p
        >
      </div>
      <ElSpace wrap>
        <ElButton :loading="loading" @click="loadMonitor">
          <ArtSvgIcon icon="ri:refresh-line" class="mr-1" />
          刷新
        </ElButton>
        <ElButton
          type="danger"
          plain
          :loading="clearingAll"
          v-auth="'system:cache:clear'"
          @click="handleClearAll"
        >
          <ArtSvgIcon icon="ri:delete-bin-6-line" class="mr-1" />
          清除所有缓存
        </ElButton>
      </ElSpace>
    </div>

    <ElRow :gutter="16" class="am-dashboard-row">
      <ElCol v-for="item in summaryCards" :key="item.label" :xs="24" :sm="12" :lg="6">
        <ElCard shadow="never" class="am-dashboard-card am-metric-card">
          <div class="am-metric-card__content">
            <span class="am-metric-card__label">{{ item.label }}</span>
            <strong class="am-metric-card__value">{{ item.value }}</strong>
            <span class="am-metric-card__desc">{{ item.desc }}</span>
          </div>
          <div class="am-metric-card__icon" :class="item.className">
            <ArtSvgIcon :icon="item.icon" />
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="am-dashboard-row">
      <ElCol v-for="item in indicatorCards" :key="item.label" :xs="24" :sm="12" :lg="6">
        <ElCard shadow="never" class="am-dashboard-card am-indicator-card">
          <div class="flex justify-between items-start">
            <div class="flex items-center gap-2">
              <span class="am-indicator-card__label">{{ item.label }}</span>
              <strong class="am-indicator-card__value">{{ item.value }}</strong>
            </div>
            <div class="am-indicator-card__icon" :class="item.className">
              <ArtSvgIcon :icon="item.icon" />
            </div>
          </div>
          <div class="am-progress">
            <div class="am-progress__track">
              <div
                class="am-progress__bar"
                :style="{ width: `${item.percent}%`, background: item.color }"
              />
            </div>
            <span class="am-progress__text">{{ item.percent }}%</span>
          </div>
          <div class="am-indicator-card__footer">
            <span>{{ item.desc }}</span>
            <span>{{ item.extra }}</span>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="am-dashboard-row">
      <ElCol :xs="24" :lg="14">
        <ElCard shadow="never" class="am-dashboard-card">
          <template #header>
            <div class="am-section-title">
              <div>
                <h4>连接与指标</h4>
                <p>读取缓存连接配置、运行指标与当前业务缓存状态。</p>
              </div>
              <ElTag :type="statusTagType">{{ overview.statusText || '未知' }}</ElTag>
            </div>
          </template>
          <ElRow :gutter="12">
            <ElCol v-for="item in connectionItems" :key="item.label" :xs="24" :sm="12" :lg="8">
              <div class="am-info-item">
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </div>
            </ElCol>
          </ElRow>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="10">
        <ElCard shadow="never" class="am-dashboard-card h-full">
          <template #header>
            <div class="am-section-title">
              <div>
                <h4>状态阶段</h4>
                <p>缓存配置、驱动与连接检查状态。</p>
              </div>
            </div>
          </template>
          <div class="stage-list">
            <div v-for="stage in stages" :key="stage.title" class="stage-item">
              <ElIcon class="stage-item__icon" :class="`is-${stage.status}`">
                <CircleCheck v-if="stage.status === 'success'" />
                <Warning v-else-if="stage.status === 'warning'" />
                <CircleClose v-else-if="stage.status === 'error'" />
                <InfoFilled v-else />
              </ElIcon>
              <div>
                <strong>{{ stage.title }}</strong>
                <p>{{ stage.message }}</p>
              </div>
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="am-dashboard-row">
      <ElCol :xs="24" :lg="24">
        <ElCard shadow="never" class="am-dashboard-card">
          <template #header>
            <div class="am-section-title">
              <div>
                <h4>业务缓存空间</h4>
                <p
                  >只允许清理已在后台 cache_manage.php
                  声明的业务缓存，避免误删登录态、验证码等运行数据。</p
                >
              </div>
              <ElTag type="success">可清理</ElTag>
            </div>
          </template>
          <ElRow :gutter="12">
            <ElCol v-for="item in namespaces" :key="item.code" :xs="12" :sm="8" :lg="6">
              <div class="namespace-card">
                <div class="namespace-card__icon">
                  <ArtSvgIcon :icon="namespaceIcon(item.code)" />
                </div>
                <div class="namespace-card__body">
                  <div class="namespace-card__head">
                    <strong>{{ item.name }}</strong>
                    <ElButton
                      size="small"
                      type="primary"
                      link
                      v-auth="'system:cache:clear'"
                      :loading="clearingCode === item.code"
                      @click="handleClearNamespace(item)"
                    >
                      清理
                    </ElButton>
                  </div>
                  <p>{{ item.description || item.code }}</p>
                  <div class="namespace-card__meta">
                    <ElTag size="small" type="info">{{ item.code }}</ElTag>
                    <span>Key：{{ displayValue(item.keyCount, '不可统计') }}</span>
                    <span>过期：{{ item.expireText || '-' }}</span>
                  </div>
                  <div class="namespace-card__key">
                    {{ item.prefix || item.key || item.tag || '-' }}
                  </div>
                </div>
              </div>
            </ElCol>
          </ElRow>
        </ElCard>
      </ElCol>
    </ElRow>
  </div>
</template>

<script setup lang="ts">
  import { CircleCheck, CircleClose, InfoFilled, Warning } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import cacheApi from '@/api/system/cache'
  import { clearLocalDataCache } from '@/utils/sys/local-cache'

  defineOptions({ name: 'SystemCache' })

  interface CacheOverview {
    driver?: string
    isRedis?: boolean
    status?: string
    statusText?: string
    engine?: string
    keyCount?: number
    memoryHuman?: string
    opsPerSecond?: number
    connectedClients?: number
    hitRate?: number
    updatedAt?: string
  }

  interface CacheConnection {
    host?: string
    port?: number
    database?: number
    prefix?: string
    timeout?: string
    latencyMs?: number | null
    memoryHuman?: string
    opsPerSecond?: number
    message?: string
  }

  interface CacheStage {
    title: string
    status: string
    message: string
  }

  interface CacheNamespace {
    code: string
    name: string
    description?: string
    tag?: string
    key?: string | null
    prefix?: string | null
    expire?: number
    expireText?: string
    keyCount?: number | null
    clearable?: boolean
  }

  interface CacheMonitor {
    overview?: CacheOverview
    connection?: CacheConnection
    stages?: CacheStage[]
    namespaces?: CacheNamespace[]
  }

  const loading = ref(false)
  const clearingAll = ref(false)
  const clearingCode = ref('')
  const overview = ref<CacheOverview>({})
  const connection = ref<CacheConnection>({})
  const stages = ref<CacheStage[]>([])
  const namespaces = ref<CacheNamespace[]>([])

  const statusTagType = computed(() => {
    if (overview.value.status === 'connected') return 'success'
    if (overview.value.status === 'warning') return 'warning'
    if (overview.value.status === 'error') return 'danger'
    return 'info'
  })

  const summaryCards = computed(() => [
    {
      label: '引擎',
      value: overview.value.engine || '-',
      desc: overview.value.driver || '未配置',
      icon: 'ri:database-2-line',
      className: 'am-tint-blue'
    },
    {
      label: '状态',
      value: overview.value.statusText || '-',
      desc: connection.value.message || '等待检测',
      icon: 'ri:checkbox-circle-line',
      className: 'am-tint-green'
    },
    {
      label: 'Key 数量',
      value: displayValue(overview.value.keyCount),
      desc: `${namespaces.value.length} 个业务空间`,
      icon: 'ri:key-2-line',
      className: 'am-tint-cyan'
    },
    {
      label: '内存',
      value: overview.value.memoryHuman || '0B',
      desc: '当前使用量',
      icon: 'ri:hard-drive-2-line',
      className: 'am-tint-orange'
    }
  ])

  const indicatorCards = computed(() => [
    {
      label: '命中率',
      value: `${displayValue(overview.value.hitRate)}%`,
      percent: clamp(overview.value.hitRate),
      desc: '缓存效率',
      extra: overview.value.hitRate && overview.value.hitRate >= 50 ? '良好' : '需优化',
      icon: 'ri:crosshair-2-line',
      className: 'am-tint-blue',
      color: '#3b82f6'
    },
    {
      label: 'Key 数量',
      value: displayValue(overview.value.keyCount),
      percent: clamp((overview.value.keyCount || 0) / 2),
      desc: '键空间',
      extra: `${displayValue(overview.value.keyCount)} 个缓存键`,
      icon: 'ri:key-2-line',
      className: 'am-tint-cyan',
      color: '#06b6d4'
    },
    {
      label: 'Ops/s',
      value: displayValue(overview.value.opsPerSecond),
      percent: clamp((overview.value.opsPerSecond || 0) * 5),
      desc: '吞吐量',
      extra: `每秒 ${displayValue(overview.value.opsPerSecond)} 次操作`,
      icon: 'ri:speed-up-line',
      className: 'am-tint-orange',
      color: '#f59e0b'
    },
    {
      label: '客户端连接',
      value: displayValue(overview.value.connectedClients),
      percent: clamp((overview.value.connectedClients || 0) * 10),
      desc: '活跃连接',
      extra: `${displayValue(overview.value.connectedClients)} 个客户端`,
      icon: 'ri:links-line',
      className: 'am-tint-green',
      color: '#10b981'
    }
  ])

  const connectionItems = computed(() => [
    {
      label: '连接地址',
      value: connection.value.host ? `${connection.value.host}:${connection.value.port}` : '未配置'
    },
    { label: 'Key 前缀', value: connection.value.prefix || '-' },
    { label: '数据库', value: displayValue(connection.value.database) },
    { label: '超时时间', value: connection.value.timeout || '-' },
    { label: '内存使用', value: connection.value.memoryHuman || '0B' },
    { label: '每秒操作', value: `${displayValue(connection.value.opsPerSecond)} ops/s` }
  ])

  onMounted(() => {
    loadMonitor()
  })

  async function loadMonitor() {
    loading.value = true
    try {
      const data = (await cacheApi.monitor()) as CacheMonitor
      overview.value = data.overview || {}
      connection.value = data.connection || {}
      stages.value = data.stages || []
      namespaces.value = data.namespaces || []
    } finally {
      loading.value = false
    }
  }

  async function handleClearNamespace(item: CacheNamespace) {
    await ElMessageBox.confirm(
      `确定清理「${item.name}」缓存吗？该操作只会清理当前业务缓存空间。`,
      '清理确认',
      {
        type: 'warning',
        confirmButtonText: '确定清理',
        cancelButtonText: '取消'
      }
    )
    clearingCode.value = item.code
    try {
      await cacheApi.clear(item.code)
      ElMessage.success('清理成功')
      await loadMonitor()
    } finally {
      clearingCode.value = ''
    }
  }

  async function handleClearAll() {
    await ElMessageBox.confirm(
      '确定清理所有业务缓存吗？该操作仅清理 cache_manage.php 声明的业务缓存，不会清空 Redis DB，并会同时清理浏览器本地的字典、远程数据等数据快照（不影响登录态与界面设置）。服务端还会失效本进程的控制器白名单与插件状态备忘，其它 worker 由其短 TTL 自然收敛。',
      '清除确认',
      {
        type: 'warning',
        confirmButtonText: '确定清理',
        cancelButtonText: '取消'
      }
    )
    clearingAll.value = true
    try {
      await cacheApi.clear()
      clearLocalDataCache()
      ElMessage.success('线上缓存与本地缓存已清理')
      await loadMonitor()
    } finally {
      clearingAll.value = false
    }
  }

  function displayValue(value: unknown, fallback = '0') {
    if (value === null || value === undefined || value === '') return fallback
    return String(value)
  }

  function clamp(value: unknown) {
    const num = Number(value || 0)
    return Math.max(0, Math.min(100, Number.isFinite(num) ? num : 0))
  }

  function namespaceIcon(code: string) {
    const icons: Record<string, string> = {
      user: 'ri:user-settings-line',
      auth: 'ri:shield-keyhole-line',
      menu: 'ri:menu-line',
      dict: 'ri:book-2-line',
      config: 'ri:settings-3-line'
    }
    return icons[code] || 'ri:database-2-line'
  }
</script>

<style scoped lang="scss">
  .stage-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .stage-item {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;

    strong {
      font-size: 14px;
    }

    p {
      margin: 4px 0 0;
      font-size: 12px;
      line-height: 1.5;
      color: var(--art-gray-600);
    }
  }

  .stage-item__icon {
    margin-top: 2px;
    font-size: 18px;

    &.is-success {
      color: var(--el-color-success);
    }

    &.is-warning {
      color: var(--el-color-warning);
    }

    &.is-error {
      color: var(--el-color-danger);
    }
  }

  .namespace-card {
    position: relative;
    display: flex;
    gap: 12px;
    min-height: 132px;
    padding: 14px;
    margin-bottom: 12px;
    overflow: hidden;
    background:
      linear-gradient(135deg, rgb(64 158 255 / 7%), transparent 42%),
      linear-gradient(180deg, var(--el-fill-color-blank), var(--el-fill-color-lighter));
    border: 1px solid var(--el-color-primary-light-9);
    border-radius: 14px;
    transition:
      border-color 0.2s ease,
      box-shadow 0.2s ease,
      transform 0.2s ease;

    &::before {
      position: absolute;
      top: 0;
      left: 0;
      width: 3px;
      height: 100%;
      content: '';
      background: linear-gradient(180deg, var(--el-color-primary), var(--el-color-primary-light-5));
      opacity: 0.7;
    }

    &:hover {
      border-color: var(--el-color-primary-light-5);
      box-shadow:
        inset 0 1px 0 rgb(255 255 255 / 60%),
        0 10px 10px var(--el-color-primary-light-9);
      transform: translateY(-2px);
    }
  }

  .namespace-card__icon {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    font-size: 20px;
    color: var(--el-color-primary);
    background: linear-gradient(135deg, rgb(64 158 255 / 16%), rgb(64 158 255 / 6%));
    border: 1px solid rgb(64 158 255 / 12%);
    border-radius: 14px;
    box-shadow: 0 6px 14px rgb(64 158 255 / 10%);
  }

  .namespace-card__body {
    position: relative;
    z-index: 1;
    flex: 1;
    min-width: 0;

    p {
      margin: 6px 0;
      font-size: 12px;
      line-height: 1.5;
      color: var(--art-gray-600);
    }
  }

  .namespace-card__head,
  .namespace-card__meta {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
  }

  .namespace-card__meta {
    flex-wrap: wrap;
    justify-content: flex-start;
    font-size: 12px;
    color: var(--art-gray-500);
  }

  .namespace-card__key {
    padding: 7px 9px;
    margin-top: 8px;
    font-size: 12px;
    color: var(--art-gray-600);
    word-break: break-all;
    background: var(--art-gray-200);
    backdrop-filter: blur(6px);
    border: 1px solid rgb(64 158 255 / 8%);
    border-radius: 8px;
  }

  @media (width <= 768px) {
    .stage-list {
      grid-template-columns: 1fr;
    }
  }
</style>
