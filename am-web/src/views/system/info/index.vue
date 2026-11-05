<template>
  <div class="sys-page" v-loading="loading">
    <div class="sys-hero art-card">
      <div class="hero-top">
        <div>
          <div class="flex items-center gap-2">
            <h3 class="sys-title">系统与升级</h3>
            <ElTag type="primary" effect="dark" size="small">v{{ appVersion || '-' }}</ElTag>
            <!-- 超管的版本状态由下方升级条统一承载，这里仅给普通角色展示发布状态 -->
            <ElTag v-if="!isSuperAdmin" :type="versionState.type" effect="light" size="small">
              {{ versionState.text }}
            </ElTag>
            <ElTag v-if="maintaining" type="danger" effect="dark" size="small">维护中</ElTag>
          </div>
          <p class="sys-desc">
            展示主程序版本、运行环境、核心组件与应用统计，并提供主程序升级入口；服务器资源占用请查看「服务器监控」。
          </p>
        </div>
        <ElSpace wrap>
          <ElTag type="info" effect="plain" size="large" class="text-sm">{{ updatedAt || '-' }}</ElTag>
          <ElButton type="primary" :loading="loading" @click="loadAll">
            <ArtSvgIcon icon="ri:refresh-line" class="mr-1" />
            刷新
          </ElButton>
        </ElSpace>
      </div>

      <!-- 升级检查与执行入口内聚在页头：状态 + 摘要 + 操作一行完成，不再单独占用卡片头部 -->
      <div v-if="isSuperAdmin" class="hero-update">
        <div class="update-status">
          <div class="update-badge" :class="upgradeBar.tone">
            <ArtSvgIcon :icon="upgradeBar.icon" :class="{ spin: running }" />
          </div>
          <div class="update-status__text">
            <div class="update-status__title">
              <strong>{{ upgradeBar.title }}</strong>
              <ElTag
                v-if="hasPlan"
                size="small"
                :type="envReady ? 'success' : 'warning'"
                effect="plain"
              >
                {{ envReady ? '环境就绪' : '需处理环境' }}
              </ElTag>
            </div>
            <p :class="{ 'text-danger': !!planError && !running }">{{ upgradeBar.desc }}</p>
          </div>
        </div>
        <ElSpace wrap align="center" class="update-actions" :size="10">
          <ElButton
            type="primary"
            plain
            :loading="planning"
            :disabled="running"
            @click="loadPlan"
          >
            <ArtSvgIcon icon="ri:refresh-line" class="mr-1" />
            检查更新
          </ElButton>
          <template v-if="hasPlan">
            <ElRadioGroup v-model="strategy" :disabled="running">
              <ElRadioButton value="merged">合并执行</ElRadioButton>
              <ElRadioButton value="sequential">逐级执行</ElRadioButton>
            </ElRadioGroup>
            <ElButton type="danger" :loading="running" :disabled="!canExecute" @click="handleExecute">
              <ArtSvgIcon icon="ri:rocket-line" class="mr-1" />
              {{ running ? '升级执行中' : '开始升级' }}
            </ElButton>
            <span v-if="needComposerInstall && !envReady" class="exec-hint">
              需在线安装依赖，请先处理环境问题
            </span>
          </template>
        </ElSpace>
      </div>
    </div>

    <!-- 升级详情：存在可执行计划且尚未开始升级时出现，供执行前核对链路与环境；升级开始后隐藏，仅保留进度卡片 -->
    <ElCard v-if="isSuperAdmin && hasPlan && !running" shadow="never" class="section-card sys-row">
      <template #header>
        <div class="section-title">
          <div>
            <h4>升级详情</h4>
            <p>
              确认增量链路与执行环境；升级不会覆盖 <code>.env</code>、<code>runtime</code>
              与上传目录。
            </p>
          </div>
          <ElSpace wrap>
            <ElTag size="small" type="primary">{{ plan.packages.length }} 步</ElTag>
            <ElTag size="small" :type="summary.need_restart ? 'warning' : 'success'">
              {{ summary.need_restart ? '升级后自动重载' : '无需重启' }}
            </ElTag>
          </ElSpace>
        </div>
      </template>

      <ElAlert
        class="mb-4"
        type="warning"
        :closable="false"
        show-icon
        title="升级期间全站进入维护模式，除本页面外访客与业务写请求都会被拦截；执行前请先自行备份数据库。"
      />

      <ElRow :gutter="16">
        <ElCol :xs="24" :lg="12">
          <div class="sub-title">
            <span>升级链路</span>
          </div>
          <p class="sub-desc">由应用插件按当前版本计算，逐级递进；链不完整时不允许升级。</p>
          <ElSteps direction="vertical" :active="plan.packages.length" class="chain-steps">
            <ElStep
              v-for="pkg in plan.packages"
              :key="pkg.version"
              status="process"
              :title="`v${pkg.delta_from_version || currentVersion} → v${pkg.version}`"
              :description="describePackage(pkg)"
            />
          </ElSteps>
        </ElCol>

        <ElCol :xs="24" :lg="12">
          <div class="sub-title">
            <span>执行环境与内容</span>
          </div>
          <p class="sub-desc">依赖需在线安装时，四项环境检查必须全部通过。</p>
          <div class="kv-list">
            <div class="kv-item">
              <span class="kv-item__label">目标版本</span>
              <div class="kv-item__value">
                <ElTag type="primary" size="small">v{{ latestVersion }}</ElTag>
              </div>
            </div>
            <div class="kv-item">
              <span class="kv-item__label">差异文件</span>
              <div class="kv-item__value">
                {{ changedFilesText }}
                <span class="cell-desc">{{ changedFilesDesc }}</span>
              </div>
            </div>
            <div class="kv-item">
              <span class="kv-item__label">包体合计</span>
              <div class="kv-item__value">
                {{ formatSize(Number(summary.size_total || 0)) }}
              </div>
            </div>
            <div class="kv-item">
              <span class="kv-item__label">在线安装依赖</span>
              <div class="kv-item__value">
                <ElTag size="small" :type="env.composer_enabled ? 'success' : 'info'">
                  开关{{ env.composer_enabled ? '已开' : '已关' }}
                </ElTag>
                <ElTag size="small" :type="env.composer_installed ? 'success' : 'danger'">
                  composer{{ env.composer_installed ? '可用' : '缺失' }}
                </ElTag>
                <ElTag size="small" :type="env.proc_open_available ? 'success' : 'danger'">
                  proc_open
                </ElTag>
                <ElTag size="small" :type="env.vendor_writable ? 'success' : 'danger'">
                  vendor 可写
                </ElTag>
              </div>
            </div>
            <div v-if="composerList.length" class="kv-item">
              <span class="kv-item__label">依赖变更</span>
              <div class="kv-item__value">
                <ElTag v-for="item in composerList" :key="item.name" size="small" effect="plain">
                  {{ item.name }}{{ item.constraint ? ' ' + item.constraint : '' }}
                </ElTag>
              </div>
            </div>
            <div v-if="migrationList.length" class="kv-item">
              <span class="kv-item__label">数据库脚本</span>
              <div class="kv-item__value">
                <ElTag
                  v-for="item in migrationList"
                  :key="item"
                  size="small"
                  type="warning"
                  effect="plain"
                >
                  {{ item }}
                </ElTag>
              </div>
            </div>
            <div v-if="notAllowedList.length" class="kv-item">
              <span class="kv-item__label">白名单外依赖</span>
              <div class="kv-item__value">
                <span class="text-danger">
                  {{ notAllowedList.join('、') }} 未在 composer 白名单内，将不会被安装
                </span>
              </div>
            </div>
            <div v-if="unsupportedList.length" class="kv-item">
              <span class="kv-item__label">暂不支持字段</span>
              <div class="kv-item__value">
                <span>{{ unsupportedList.join('、') }}（本次忽略，不影响其余步骤）</span>
              </div>
            </div>
          </div>
        </ElCol>
      </ElRow>
    </ElCard>

    <!-- 升级进度终端：任务提交后出现，结束后可在此重新检查更新 -->
    <ElCard v-if="isSuperAdmin && jobId" shadow="never" class="section-card sys-row">
      <template #header>
        <div class="section-title">
          <div>
            <h4>升级进度</h4>
            <p>任务 #{{ jobId }}</p>
          </div>
          <ElTag :type="runStatusTag.type" size="small" effect="dark">
            {{ runStatusTag.text }}
          </ElTag>
        </div>
      </template>
      <div ref="terminalRef" class="terminal">
        <div v-for="(line, index) in logLines" :key="index" class="terminal-line">{{ line }}</div>
        <div v-if="running" class="terminal-line terminal-cursor">▍</div>
      </div>
      <div v-if="doneMessage" class="done-message" :class="doneOk ? 'is-ok' : 'is-fail'">
        {{ doneMessage }}
      </div>
      <div v-if="!running && showRetry" class="mt-3">
        <ElButton type="primary" plain @click="loadPlan">重新检查更新</ElButton>
      </div>
    </ElCard>

    <ElRow :gutter="16" class="sys-row">
      <ElCol v-for="item in summaryCards" :key="item.label" :xs="24" :sm="12" :lg="6">
        <ElCard shadow="never" class="metric-card">
          <div class="metric-card__content">
            <span class="metric-card__label">{{ item.label }}</span>
            <ElTooltip
              :content="String(item.value)"
              placement="top"
              :disabled="!shouldShowTooltip(item.value)"
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

    <ElRow :gutter="16" class="sys-row">
      <ElCol :xs="24" :lg="12">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>主程序</h4>
                <p>版本来源与升级落库口径一致，升级后重启生效。</p>
              </div>
              <ElTag v-if="app.version" size="small" type="primary">v{{ app.version }}</ElTag>
            </div>
          </template>
          <div class="info-grid">
            <div class="info-item">
              <span>应用标识</span>
              <ElTooltip
                :content="String(app.name || '-')"
                placement="top"
                :disabled="!shouldShowTooltip(app.name)"
              >
                <strong>{{ app.name || '-' }}</strong>
              </ElTooltip>
            </div>
            <div class="info-item">
              <span>应用名称</span>
              <ElTooltip
                :content="String(app.title || '-')"
                placement="top"
                :disabled="!shouldShowTooltip(app.title)"
              >
                <strong>{{ app.title || '-' }}</strong>
              </ElTooltip>
            </div>
            <div class="info-item">
              <span>发布标识</span>
              <ElTooltip
                :content="String(app.release || '-')"
                placement="top"
                :disabled="!shouldShowTooltip(app.release)"
              >
                <strong>{{ app.release || '-' }}</strong>
              </ElTooltip>
            </div>
            <div class="info-item">
              <span>数据表前缀</span>
              <strong>{{ app.table_prefix || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>时区</span>
              <strong>{{ app.timezone || '-' }}</strong>
            </div>
            <div class="info-item info-item--block">
              <span>运行环境</span>
              <div class="info-item__value">
                <ElTag :type="app.env === 'production' ? 'success' : 'warning'">
                  {{ app.env || '-' }}
                </ElTag>
                <ElTag :type="app.debug ? 'danger' : 'info'">
                  调试{{ app.debug ? '开启' : '关闭' }}
                </ElTag>
              </div>
            </div>
          </div>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="12">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>PHP 与系统</h4>
                <p>运行时版本、接口类型与关键 php.ini 限制。</p>
              </div>
              <ElTag size="small" type="success">{{ php.sapi || '-' }}</ElTag>
            </div>
          </template>
          <div class="info-grid">
            <div class="info-item">
              <span>PHP 版本</span>
              <strong>{{ php.php_version || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>操作系统</span>
              <ElTooltip
                :content="String(php.os || '-')"
                placement="top"
                :disabled="!shouldShowTooltip(php.os)"
              >
                <strong>{{ php.os || '-' }}</strong>
              </ElTooltip>
            </div>
            <div class="info-item">
              <span>内存上限</span>
              <strong>{{ php.memory_limit || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>最大执行时间</span>
              <strong>{{ php.max_execution_time ? php.max_execution_time + ' 秒' : '-' }}</strong>
            </div>
            <div class="info-item">
              <span>上传大小限制</span>
              <strong>{{ php.upload_max_filesize || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>Post 大小限制</span>
              <strong>{{ php.post_max_size || '-' }}</strong>
            </div>
            <div class="info-item info-item--block">
              <span>关键扩展（{{ extensions.length }}）</span>
              <div class="info-item__value">
                <ElTag
                  v-for="ext in extensions"
                  :key="ext.name"
                  :type="ext.loaded ? 'success' : 'danger'"
                >
                  {{ ext.name }}
                </ElTag>
                <span v-if="!extensions.length" class="art-text-gray">-</span>
              </div>
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <ElRow :gutter="16" class="sys-row">
      <ElCol :xs="24" :lg="10">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>核心组件</h4>
                <p>Composer 依赖的实际安装版本。</p>
              </div>
              <ElTag size="small" type="info">{{ components.length }} 个</ElTag>
            </div>
          </template>
          <div class="comp-list">
            <div v-for="item in components" :key="item.package" class="comp-item">
              <span class="comp-item__label">{{ item.label }}</span>
              <ElTooltip
                :content="item.package"
                placement="top"
                :disabled="!shouldShowTooltip(item.package)"
              >
                <span class="comp-item__pkg text-ellipsis">{{ item.package }}</span>
              </ElTooltip>
              <strong class="comp-item__version">{{ item.version || '-' }}</strong>
            </div>
            <ElEmpty v-if="!components.length" description="暂无组件信息" :image-size="60" />
          </div>
        </ElCard>
      </ElCol>

      <ElCol :xs="24" :lg="14">
        <ElCard shadow="never" class="section-card h-full">
          <template #header>
            <div class="section-title">
              <div>
                <h4>存储与应用</h4>
                <p>数据库、Redis 版本与已安装应用插件统计。</p>
              </div>
            </div>
          </template>
          <div class="info-grid info-grid--3">
            <div class="info-item">
              <span>数据库</span>
              <strong>{{ database.driver || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>数据库版本</span>
              <strong>{{ database.version || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>Redis 版本</span>
              <strong>{{ redis.version || '-' }}</strong>
            </div>
            <div class="info-item">
              <span>Redis 库号</span>
              <strong>{{ redis.database ?? '-' }}</strong>
            </div>
            <div class="info-item">
              <span>应用总数</span>
              <strong>{{ plugins.total ?? 0 }} 个</strong>
            </div>
            <div class="info-item">
              <span>启用中</span>
              <strong class="is-success-text">{{ plugins.enabled ?? 0 }} 个</strong>
            </div>
          </div>
        </ElCard>
      </ElCol>
    </ElRow>

    <!-- 升级成功后提示重载：核心升级替换了后台前端产物，需刷新浏览器加载新版本 -->
    <ElDialog
      v-model="reloadVisible"
      title="升级完成"
      width="420px"
      align-center
      append-to-body
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      :show-close="false"
    >
      <div class="restart-reload">
        <ArtSvgIcon icon="ri:checkbox-circle-fill" class="restart-reload__icon" />
        <p class="restart-reload__title">升级完成，正在重启服务</p>
        <p class="restart-reload__desc">
          {{ reloadCountdown > 0 ? `${reloadCountdown} 秒后自动重载页面…` : '正在重载页面…' }}
        </p>
      </div>
      <template #footer>
        <ElButton type="primary" :disabled="reloadCountdown > 0" @click="doReloadPage">
          重载页面
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
  import { ElMessageBox } from 'element-plus'
  import { useUserStore } from '@/store/modules/user'
  import infoApi from '@/api/system/info'
  import upgradeApi from '@/api/system/upgrade'

  defineOptions({ name: 'SystemInfo' })

  type Strategy = 'merged' | 'sequential'

  interface ExtensionItem {
    name: string
    loaded: number
  }

  interface ComponentItem {
    label: string
    package: string
    version: string
  }

  const userStore = useUserStore()
  const isSuperAdmin = computed(() => Boolean(userStore.getUserInfo?.is_admin))

  const loading = ref(false)
  const updatedAt = ref('')
  const app = ref<Record<string, any>>({})
  const php = ref<Record<string, any>>({})
  const database = ref<Record<string, any>>({})
  const redis = ref<Record<string, any>>({})
  const plugins = ref<Record<string, any>>({})
  const update = ref<Record<string, any>>({})
  const components = ref<ComponentItem[]>([])
  const extensions = ref<ExtensionItem[]>([])

  const planning = ref(false)
  const running = ref(false)
  const plan = ref<Record<string, any>>({ packages: [], summary: {}, env: {} })
  const planLoaded = ref(false)
  const planError = ref('')
  const maintaining = ref(false)

  const strategy = ref<Strategy>('merged')
  const jobId = ref('')
  const logLines = ref<string[]>([])
  const shownCount = ref(0)
  const runStatus = ref('')
  const doneOk = ref(false)
  const showRetry = ref(false)
  const doneMessage = ref('')
  const terminalRef = ref<HTMLElement>()

  let pollTimer: ReturnType<typeof setInterval> | null = null

  // 升级成功后的「重载页面」提示：倒计时到 0 自动 reload；按钮在倒计时结束前置灰，
  // 若到时未自动重载（如被浏览器拦截），用户可手动点击重载
  const reloadVisible = ref(false)
  const reloadCountdown = ref(5)
  let reloadTimer: ReturnType<typeof setInterval> | null = null
  const clearReloadTimer = () => {
    if (reloadTimer) {
      clearInterval(reloadTimer)
      reloadTimer = null
    }
  }
  const doReloadPage = () => {
    clearReloadTimer()
    window.location.reload()
  }
  const showReloadDialog = () => {
    clearReloadTimer()
    reloadCountdown.value = 5
    reloadVisible.value = true
    reloadTimer = setInterval(() => {
      reloadCountdown.value -= 1
      if (reloadCountdown.value <= 0) doReloadPage()
    }, 1000)
  }
  let pollFailures = 0

  const appVersion = computed(() => app.value.version || '')

  const summary = computed(() => plan.value.summary || {})
  const env = computed(() => plan.value.env || {})
  const currentVersion = computed(() => plan.value.current_version || appVersion.value)
  const latestVersion = computed(() => plan.value.latest_version || '')
  const hasUpdate = computed(() => Boolean(plan.value.has_update))
  // 只有增量链连通（mode=delta）才存在可执行计划；none/error 一律走上方提示区
  const hasPlan = computed(() => plan.value.mode === 'delta' && Boolean(plan.value.latest_version))
  // 超管以升级计划为权威口径，其余角色只能用系统信息里的发布状态
  const hasNewVersion = computed(() =>
    isSuperAdmin.value && planLoaded.value ? hasUpdate.value : Boolean(update.value.has_update)
  )
  const targetVersion = computed(() => latestVersion.value || update.value.latest_version || '')

  const composerList = computed(() =>
    Object.entries((summary.value.composer_requires || {}) as Record<string, any>).map(
      ([name, constraint]) => ({ name, constraint: String(constraint || '') })
    )
  )
  const migrationList = computed(() => (summary.value.migrations || []) as string[])
  const notAllowedList = computed(() => (env.value.not_allowed || []) as string[])
  const unsupportedList = computed(() => (summary.value.unsupported_config || []) as string[])

  const envReady = computed(
    () =>
      Boolean(env.value.composer_enabled) &&
      Boolean(env.value.composer_installed) &&
      Boolean(env.value.proc_open_available) &&
      Boolean(env.value.vendor_writable)
  )
  const needComposerInstall = computed(() => composerList.value.length > 0)
  const canExecute = computed(
    () =>
      isSuperAdmin.value &&
      hasPlan.value &&
      !running.value &&
      plan.value.mode === 'delta' &&
      (!needComposerInstall.value || envReady.value)
  )

  // 包未声明差异清单时，执行会按包内实际文件全量覆盖，计划里不能把数量当成 0
  const changedFilesText = computed(() =>
    summary.value.changed_declared === false ? '未声明' : `${summary.value.changed_count ?? 0} 个`
  )
  const changedFilesDesc = computed(() =>
    summary.value.changed_declared === false
      ? '包未声明清单，执行时按包内文件覆盖'
      : `待执行脚本 ${migrationList.value.length} 个`
  )

  const versionState = computed(() => {
    if (hasNewVersion.value) {
      return {
        text: `${hasPlan.value ? '可升级到' : '有新版本'} v${targetVersion.value}`,
        type: 'warning' as const
      }
    }
    if (!update.value.enabled) return { text: '未开启更新检查', type: 'info' as const }
    if (planError.value) return { text: '暂不可升级', type: 'info' as const }
    return { text: '已是最新版本', type: 'success' as const }
  })

  const versionCardDesc = computed(() => {
    if (hasNewVersion.value) return `目标 v${targetVersion.value}`
    if (!update.value.enabled) return '未开启更新检查'
    if (planError.value) return '暂不可升级'
    return '当前为最新版本'
  })

  // 页头升级条的单一状态源：图标配色 + 标题 + 一行摘要，替代原先卡片头部的多个状态标签
  const upgradeBar = computed(() => {
    if (running.value) {
      return {
        tone: 'is-orange',
        icon: 'ri:loader-4-line',
        title: '升级执行中',
        desc: `正在升级到 v${latestVersion.value}，请勿关闭页面或停止队列消费进程，实时日志见下方进度卡片`
      }
    }
    if (planError.value) {
      return {
        tone: 'is-danger',
        icon: 'ri:error-warning-line',
        title: '暂不可升级',
        desc: planError.value
      }
    }
    if (hasPlan.value) {
      const steps = summary.value.package_count ?? plan.value.packages?.length ?? 0
      const desc = [
        `v${currentVersion.value} → v${latestVersion.value}`,
        `${steps} 步`,
        `差异 ${changedFilesText.value}`,
        formatSize(Number(summary.value.size_total || 0))
      ].join(' · ')
      return {
        tone: 'is-blue',
        icon: 'ri:download-cloud-2-line',
        title: `发现新版本 v${latestVersion.value}`,
        desc
      }
    }
    if (planLoaded.value && !hasUpdate.value) {
      return {
        tone: 'is-green',
        icon: 'ri:checkbox-circle-line',
        title: '已是最新版本',
        desc: `当前 v${appVersion.value || '-'}，应用插件暂无可用更新`
      }
    }
    return {
      tone: 'is-muted',
      icon: 'ri:arrow-up-circle-line',
      title: '尚未检查更新',
      desc: planning.value ? '正在获取升级计划…' : '点击「检查更新」，由应用插件计算增量升级链路'
    }
  })

  const summaryCards = computed(() => [
    {
      label: '主程序版本',
      value: `v${appVersion.value || '-'}`,
      desc: versionCardDesc.value,
      icon: 'ri:rocket-2-line',
      className: 'is-blue'
    },
    {
      label: 'PHP 版本',
      value: php.value.php_version || '-',
      desc: `${php.value.sapi || '-'} · ${php.value.os || '-'}`,
      icon: 'ri:php-line',
      className: 'is-orange'
    },
    {
      label: '应用插件',
      value: `${plugins.value.enabled ?? 0} / ${plugins.value.total ?? 0}`,
      desc: '启用中 / 已安装总数',
      icon: 'ri:apps-2-line',
      className: 'is-green'
    },
    {
      label: '数据库',
      value: database.value.version || '-',
      desc: `${database.value.driver || 'mysql'} · 前缀 ${database.value.prefix || '-'}`,
      icon: 'ri:database-2-line',
      className: 'is-cyan'
    }
  ])

  const runStatusTag = computed(() => {
    const map: Record<string, { text: string; type: 'success' | 'warning' | 'danger' | 'info' }> =
      {
        queued: { text: '排队中', type: 'info' },
        running: { text: '执行中', type: 'warning' },
        success: { text: '升级成功', type: 'success' },
        failed: { text: '升级失败', type: 'danger' }
      }
    return map[runStatus.value] || { text: '准备中', type: 'info' as const }
  })

  function formatSize(bytes: number): string {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB'
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB'
    return bytes + ' B'
  }

  /** 更新日志逐行拆开：一行一条，配合描述单元格的 pre-line 才不会被压成一句 */
  function splitChangelog(value: unknown): string[] {
    return String(value ?? '')
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean)
  }

  function describePackage(pkg: Record<string, any>): string {
    const config = pkg.upgrade_config || {}
    // 逐步不报「差异 N 个」：只有包内声明 upgrade_config 才有清单，未声明时恒为 0 会误导，
    // 差异数量看汇总行（changedFilesText 区分「未声明 / N 个」）即可
    const parts = [formatSize(Number(pkg.size || 0))]
    if ((config.migrations || []).length) parts.push(`脚本 ${config.migrations.length} 个`)
    return [...parts, ...splitChangelog(pkg.changelog)].join('\n')
  }

  function shouldShowTooltip(value: unknown) {
    return String(value ?? '').length > 12
  }

  async function loadInfo() {
    try {
      const data = (await infoApi.index()) as Record<string, any>
      app.value = data.app || {}
      php.value = data.php || {}
      database.value = data.database || {}
      redis.value = data.redis || {}
      plugins.value = data.plugins || {}
      update.value = data.update || {}
      components.value = data.components || []
      extensions.value = data.php?.extensions || []
      updatedAt.value = new Date().toLocaleString('zh-CN', { hour12: false })
    } finally {
      /* 失败提示由接口拦截器统一处理，此处只保留已取到的数据 */
    }
  }

  async function loadPlan() {
    planning.value = true
    planError.value = ''
    try {
      const data: any = await upgradeApi.plan()
      plan.value = { packages: [], summary: {}, env: {}, ...(data || {}) }
      planLoaded.value = true
      // 不可升级是后端正常返回（mode=error + message），不是接口异常，直接在页头升级条呈现
      if (plan.value.mode === 'error' && plan.value.message) {
        planError.value = String(plan.value.message)
      }
    } catch (e: any) {
      // 真正的请求失败（服务异常等）以错误态呈现，接口拦截器已弹过提示，这里只留可持久阅读的上下文
      plan.value = { packages: [], summary: {}, env: {} }
      planLoaded.value = true
      planError.value = e?.message || '升级计划生成失败'
    } finally {
      planning.value = false
    }
  }

  async function loadAll() {
    loading.value = true
    try {
      await Promise.all([loadInfo(), isSuperAdmin.value ? loadPlan() : Promise.resolve()])
    } finally {
      loading.value = false
    }
  }

  async function handleExecute() {
    if (!canExecute.value) return
    try {
      await ElMessageBox.confirm(
        `确认从 v${currentVersion.value} 升级到 v${latestVersion.value}？` +
          `共 ${summary.value.package_count ?? 0} 步，期间站点进入维护模式。`,
        '主程序升级确认',
        { type: 'warning', confirmButtonText: '确认升级', cancelButtonText: '再想想' }
      )
    } catch {
      // 用户取消（reject 'cancel'/'close'）不做任何处理，也不让 promise 逸出
      return
    }
    running.value = true
    runStatus.value = 'queued'
    pollFailures = 0
    logLines.value = []
    shownCount.value = 0
    doneMessage.value = ''
    doneOk.value = false
    showRetry.value = false
    try {
      const job: any = await upgradeApi.execute(strategy.value)
      jobId.value = job?.job_id || ''
      await pollOnce()
      startPoll()
    } catch {
      running.value = false
      runStatus.value = 'failed'
      doneMessage.value = '升级任务提交失败，请检查上方提示后重试'
      doneOk.value = false
      showRetry.value = true
    }
  }

  function startPoll() {
    stopPoll()
    // 轮询任务快照：每次只追加新行，实现终端式逐行输出（后端同时逐行推 push，行序一致）
    pollTimer = setInterval(pollOnce, 1200)
  }

  function stopPoll() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function pollOnce() {
    if (!jobId.value) return
    try {
      const snap: any = await upgradeApi.status(jobId.value)
      if (!snap) return
      pollFailures = 0
      maintaining.value = Boolean(snap.maintaining)
      const log: string[] = Array.isArray(snap.log) ? snap.log : []
      // 后端日志只保留末尾 LOG_CAP 行，但额外给出累计行号：
      // 按行号对齐追加，否则日志一过上限就再也判不出“有新行”，进度会永久冻结
      const total = Number(snap.log_total ?? log.length)
      if (total > shownCount.value) {
        const dropped = Math.max(0, total - log.length)
        const start = Math.max(0, shownCount.value - dropped)
        logLines.value.push(...log.slice(start))
        shownCount.value = total
        nextTick(() => {
          const el = terminalRef.value
          if (el) el.scrollTop = el.scrollHeight
        })
      }
      if (snap.status) runStatus.value = snap.status
      if (snap.status === 'success' || snap.status === 'failed') {
        finishJob(snap)
      }
    } catch {
      // 单次轮询失败忽略（接口已静默不弹错），但连续失败要停下轮询：
      // 任务停在排队超快照 TTL 后每秒都会失败，不能无限弹下去
      pollFailures += 1
      if (pollFailures >= 5) {
        stopPoll()
        running.value = false
        runStatus.value = 'failed'
        doneOk.value = false
        doneMessage.value =
          '已连续获取不到任务进度（快照过期或任务未开始执行），请确认队列消费进程已启动后重新检查更新'
        showRetry.value = true
      }
    }
  }

  function finishJob(snap: Record<string, any>) {
    stopPoll()
    running.value = false
    maintaining.value = Boolean(snap.maintaining)
    doneOk.value = snap.status === 'success'
    doneMessage.value = String(snap.message || (doneOk.value ? '升级完成' : '升级失败'))
    showRetry.value = true
    if (!doneOk.value) return
    if (snap.reloaded === false) {
      doneMessage.value += '（未能自动重载，请手动重启服务）'
    }
    // 成功后立即作废旧升级计划：详情卡与「开始升级」按钮当场消失，避免用户再点一次重复触发升级
    plan.value = { packages: [], summary: {}, env: {} }
    planLoaded.value = false
    // 提示重载页面：核心升级已替换后台前端产物，必须刷新浏览器才能加载新版本
    showReloadDialog()
  }

  onMounted(() => {
    loadAll()
  })

  onUnmounted(() => {
    stopPoll()
    clearReloadTimer()
  })
</script>

<style scoped lang="scss">
  .sys-page {
    min-height: 100%;
    padding-bottom: 16px;
  }

  .restart-reload {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
    padding: 8px 0 4px;
    text-align: center;
  }

  .restart-reload__icon {
    font-size: 44px;
    color: var(--el-color-success);
  }

  .restart-reload__title {
    margin: 6px 0 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--art-text-gray-900);
  }

  .restart-reload__desc {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .sys-row {
    row-gap: 16px;
    margin-top: 16px;
  }

  .sys-hero {
    padding: 22px 24px;
    border: 1px solid var(--el-border-color-light);
    border-radius: var(--custom-radius);
  }

  .hero-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  .hero-update {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px dashed var(--el-border-color-light);
  }

  .update-status {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;

    .update-status__title {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    strong {
      display: block;
      font-size: 14px;
      font-weight: 700;
      line-height: 1.4;
    }

    p {
      margin: 2px 0 0;
      font-size: 12px;
      line-height: 1.5;
      color: var(--art-gray-600);
    }
  }

  .update-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    font-size: 20px;
    border-radius: 12px;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 42%);
  }

  .update-actions {
    flex-shrink: 0;
  }

  .spin {
    animation: sys-spin 1.2s linear infinite;
  }

  @keyframes sys-spin {
    to {
      transform: rotate(360deg);
    }
  }

  .sys-title {
    margin: 0;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.02em;
  }

  .sys-desc {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--art-gray-600);
  }

  .metric-card,
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

  .metric-card__content {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
  }

  .metric-card__label {
    font-size: 13px;
    color: var(--art-gray-600);
  }

  .metric-card__value {
    max-width: 100%;
    overflow: hidden;
    font-size: 26px;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.02em;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .metric-card__desc {
    max-width: 100%;
    overflow: hidden;
    font-size: 12px;
    color: var(--art-gray-500);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .metric-card__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 50px;
    height: 50px;
    font-size: 23px;
    border-radius: 14px;
    box-shadow: inset 0 1px 0 rgb(255 255 255 / 42%);
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

  .is-danger {
    color: #ef4444;
    background: linear-gradient(135deg, rgb(239 68 68 / 18%), rgb(239 68 68 / 7%));
  }

  .is-muted {
    color: var(--art-gray-500);
    background: var(--el-fill-color);
  }

  .is-success-text {
    color: var(--el-color-success);
  }

  .section-card {
    :deep(.el-card__header) {
      padding: 14px 18px;
    }
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
      font-size: 12px;
      color: var(--art-gray-600);

      code {
        padding: 1px 4px;
        font-size: 12px;
        border-radius: 4px;
        background: var(--art-gray-100);
      }
    }
  }

  .sub-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 14px;
    font-size: 14px;
    font-weight: 600;
  }

  .sub-desc {
    margin: 4px 0 10px;
    font-size: 12px;
    color: var(--art-gray-600);
  }

  .cell-desc {
    margin-left: 8px;
    font-size: 12px;
    color: var(--art-gray-500);
  }

  .exec-hint {
    font-size: 12px;
    color: var(--art-gray-600);
  }

  .chain-steps {
    padding-left: 4px;
    height: auto;
    max-height: 320px;
    overflow-y: auto;

    // EP 纵向步骤默认 height:100% 且每步 flex:1，在双列等高拉伸下会把步骤间距撑得极大并溢出卡片；
    // 改为按内容紧凑排列、限高可滚动，保证增量链路完整可读
    :deep(.el-step) {
      flex: none;
    }

    :deep(.el-step__title) {
      font-size: 14px;
      line-height: 1.6;
    }

    :deep(.el-step__description) {
      padding-right: 8px;
      font-size: 12px;
      line-height: 1.5;
      color: var(--art-gray-600);
      // 更新日志按换行原样展示（描述文本用 \n 分行），否则多条日志会被压成一行
      white-space: pre-line;
    }
  }

  .kv-list {
    padding: 4px 14px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;
  }

  .kv-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px dashed var(--el-border-color-lighter);

    &:last-child {
      border-bottom: 0;
    }
  }

  .kv-item__label {
    flex-shrink: 0;
    width: 96px;
    padding-top: 2px;
    font-size: 13px;
    color: var(--art-gray-600);
  }

  .kv-item__value {
    display: flex;
    flex: 1;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    min-width: 0;
    font-size: 13px;
  }

  .info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;

    &--3 {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  .info-item {
    min-width: 0;
    padding: 12px 14px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;

    // 只作用于直接子节点：ElTag 根节点本身也是 span，宽泛选择器会命中它，
    // 覆盖 el-tag 的 inline-flex 导致标签文字不居中
    > span {
      display: block;
      font-size: 12px;
      color: var(--art-gray-600);
    }

    > strong {
      display: block;
      max-width: 100%;
      margin-top: 8px;
      overflow: hidden;
      font-size: 14px;
      font-weight: 600;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &--block {
      grid-column: 1 / -1;
    }
  }

  .info-item__value {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin-top: 8px;
  }

  .terminal {
    max-height: 320px;
    padding: 12px 14px;
    overflow-y: auto;
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    line-height: 1.7;
    color: #d4d4d4;
    border-radius: 8px;
    background: #1e1e1e;
  }

  .terminal-line {
    white-space: pre-wrap;
    word-break: break-all;
  }

  .terminal-cursor {
    color: #4ec9b0;
    animation: blink 1s steps(1) infinite;
  }

  @keyframes blink {
    50% {
      opacity: 0;
    }
  }

  .done-message {
    margin-top: 10px;
    padding: 10px 12px;
    font-size: 13px;
    border-radius: 8px;

    &.is-ok {
      color: var(--el-color-success);
      background: var(--el-color-success-light-9);
    }

    &.is-fail {
      color: var(--el-color-danger);
      background: var(--el-color-danger-light-9);
    }
  }

  .comp-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .comp-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--el-fill-color-lighter);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;

    &__label {
      min-width: 84px;
      font-weight: 600;
    }

    &__pkg {
      flex: 1;
      min-width: 0;
      font-size: 12px;
      color: var(--art-gray-600);
    }

    &__version {
      flex-shrink: 0;
      font-size: 13px;
      color: var(--el-color-primary);
    }
  }

  :global(html.dark) .sys-page {
    .sys-hero {
      background: var(--el-color-primary-dark-2);
      border-color: var(--el-border-color-light);
    }
  }

  @media (max-width: 1280px) {
    .info-grid--3 {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 768px) {
    .hero-top,
    .hero-update {
      align-items: flex-start;
      flex-direction: column;
    }

    .update-actions {
      width: 100%;
    }
  }

  @media (max-width: 640px) {
    .info-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
