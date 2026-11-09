<template>
  <ElDialog
    :model-value="modelValue"
    title="安装插件依赖（Composer）"
    width="720px"
    align-center
    :close-on-click-modal="!installing"
    :close-on-press-escape="!installing"
    @update:model-value="handleVisibleChange"
    @closed="handleClosed"
  >
    <div class="composer-dialog">
      <div class="dialog-subtitle">
        插件「{{ plugin?.title || plugin?.name }}」的 Composer 依赖检测与一键安装。安装将合并到基座
        vendor，成功后需重载 worker 才能生效。
      </div>

      <!-- 环境探测横幅 -->
      <div v-if="env" class="env-banner">
        <ElAlert
          :type="envReady ? 'success' : 'warning'"
          show-icon
          :closable="false"
          :title="envSummary"
        />
        <ul class="env-detail">
          <li>
            Composer：{{ env.composer_installed ? `已安装 ${env.composer_version || ''}（${env.composer_bin}）` : '未检测到' }}
            <span v-if="!env.composer_installed && env.composer_probe_error" class="env-warn">
              （探测失败原因：{{ env.composer_probe_error }}）
            </span>
          </li>
          <li>PHP 版本：{{ env.php_version }}{{ state.php_ok === false ? '（不满足基座要求）' : '' }}</li>
          <li>proc_open：{{ env.proc_open_available ? '可用' : '已禁用' }}</li>
          <li>vendor / composer.json 可写：{{ env.vendor_writable ? '是' : '否' }}</li>
          <li>
            在线安装开关：{{ env.composer_enabled ? '已开启' : '未开启（composer_enabled=false）' }}
          </li>
          <li v-if="env.is_windows">当前为 Windows 环境，安装后需手动重启服务生效。</li>
          <li v-if="env.not_allowed && env.not_allowed.length" class="env-warn">
            以下包不在允许白名单内：{{ env.not_allowed.join('、') }}
          </li>
        </ul>
      </div>

      <!-- 逐包检测结果 -->
      <div class="package-block">
        <div class="block-head">
          <span class="block-title">依赖检测</span>
          <ElTag v-if="state.status" :type="statusTag.type" size="small" effect="dark">
            {{ statusTag.text }}
          </ElTag>
          <ElButton
            link
            type="primary"
            :loading="checking"
            :disabled="installing"
            @click="runCheck"
          >
            重新检测
          </ElButton>
        </div>

        <ElTable v-if="rows.length" :data="rows" size="small" class="package-table">
          <ElTableColumn prop="package" label="包名" min-width="180" />
          <ElTableColumn prop="constraint" label="需要约束" width="120" />
          <ElTableColumn prop="version" label="已装版本" width="120" />
          <ElTableColumn label="状态" width="110">
            <template #default="{ row }">
              <ElTag :type="row.tagType" size="small" effect="plain">{{ row.tagText }}</ElTag>
            </template>
          </ElTableColumn>
        </ElTable>
        <ElEmpty v-else description="该插件未声明 Composer 依赖" :image-size="60" />
      </div>

      <!-- 安装进度（终端式实时输出） -->
      <div v-if="jobId" class="terminal-block">
        <div class="block-head">
          <span class="block-title">安装输出</span>
          <ElTag :type="runStatusTag.type" size="small" effect="dark">{{ runStatusTag.text }}</ElTag>
        </div>
        <div ref="terminalRef" class="terminal">
          <div v-for="(line, index) in logLines" :key="index" class="terminal-line">{{ line }}</div>
          <div v-if="installing" class="terminal-line terminal-cursor">▍</div>
        </div>
        <div v-if="doneMessage" class="done-message" :class="doneOk ? 'is-ok' : 'is-fail'">
          {{ doneMessage }}
        </div>
      </div>

      <!-- 手动命令兜底 -->
      <div v-if="showManual" class="manual-block">
        <div class="block-title">手动执行命令</div>
        <ElInput v-model="manualCommand" readonly type="textarea" :autosize="{ minRows: 2 }">
        </ElInput>
        <ElButton link type="primary" @click="copyManual">复制命令</ElButton>
      </div>
    </div>

    <template #footer>
      <ElButton :disabled="installing" @click="handleVisibleChange(false)">关闭</ElButton>
      <ElButton
        v-if="canInstall && runStatus !== 'success'"
        type="primary"
        :loading="installing"
        @click="startInstall"
      >
        安装缺失依赖
      </ElButton>
      <ElButton
        v-if="showReload"
        type="warning"
        :loading="reloading"
        @click="manualReload"
      >
        触发重载生效
      </ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, nextTick, ref, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import pluginApi from '@/api/plugin/app'
  import type { PluginRecord } from '@/utils/plugin'

  interface Props {
    modelValue: boolean
    plugin?: PluginRecord
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', plugin: PluginRecord): void
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()

  type EnvInfo = Record<string, any>

  const checking = ref(false)
  const installing = ref(false)
  const reloading = ref(false)

  const state = ref<Record<string, any>>({})
  const env = ref<EnvInfo | null>(null)

  const jobId = ref('')
  const channel = ref('')
  const logLines = ref<string[]>([])
  const shownCount = ref(0)
  const runStatus = ref<'queued' | 'running' | 'success' | 'failed' | ''>('')
  const doneOk = ref(false)
  const doneMessage = ref('')
  const terminalRef = ref<HTMLElement>()

  let pollTimer: ReturnType<typeof setInterval> | null = null

  const rows = computed(() => {
    const requires = state.value.requires || {}
    const installed = state.value.installed || {}
    const missing = state.value.missing || {}
    const incompatible = state.value.incompatible || {}
    return Object.keys(requires).map((pkg) => {
      let tagText = '已满足'
      let tagType: 'success' | 'warning' | 'danger' = 'success'
      if (missing[pkg] !== undefined) {
        tagText = '缺失'
        tagType = 'danger'
      } else if (incompatible[pkg] !== undefined) {
        tagText = '不兼容'
        tagType = 'warning'
      }
      return {
        package: pkg,
        constraint: requires[pkg] || '*',
        version: installed[pkg] || incompatible[pkg]?.version || '-',
        tagText,
        tagType
      }
    })
  })

  const statusTag = computed(() => {
    const map: Record<string, { text: string; type: 'success' | 'warning' | 'danger' | 'info' }> = {
      satisfied: { text: '依赖已全部满足', type: 'success' },
      partial: { text: '部分依赖待安装', type: 'warning' },
      missing: { text: '依赖缺失', type: 'danger' },
      unknown: { text: '未检测', type: 'info' },
      none: { text: '无依赖', type: 'info' }
    }
    return map[state.value.status] || map.unknown
  })

  const runStatusTag = computed(() => {
    const map: Record<string, { text: string; type: 'success' | 'warning' | 'danger' | 'info' }> = {
      queued: { text: '排队中', type: 'info' },
      running: { text: '安装中', type: 'warning' },
      success: { text: '安装成功', type: 'success' },
      failed: { text: '安装失败', type: 'danger' }
    }
    return map[runStatus.value] || { text: '就绪', type: 'info' as const }
  })

  // 环境就绪：composer 存在且可执行所需操作
  const envReady = computed(() => {
    const e = env.value
    if (!e) return false
    return Boolean(e.composer_installed && e.proc_open_available && e.vendor_writable)
  })

  const envSummary = computed(() => {
    if (!env.value) return ''
    if (!env.value.composer_installed) return '未检测到 composer，无法在线安装，请安装后手动执行'
    if (!env.value.composer_enabled) return '在线安装未开启（composer_enabled=false），请手动执行'
    if (!env.value.proc_open_available) return 'PHP 已禁用 proc_open，无法在线安装，请手动执行'
    if (!env.value.vendor_writable) return '基座 vendor / composer.json 不可写，请检查权限'
    return '环境就绪，可在线安装依赖'
  })

  const installTargets = computed<Record<string, string>>(() => {
    const targets: Record<string, string> = {}
    const missing = state.value.missing || {}
    const incompatible = state.value.incompatible || {}
    Object.keys(missing).forEach((pkg) => (targets[pkg] = String(missing[pkg] || '')))
    Object.keys(incompatible).forEach((pkg) => (targets[pkg] = String(incompatible[pkg]?.want || '')))
    return targets
  })

  const canInstall = computed(() => {
    if (installing.value) return false
    if (!envReady.value || !env.value?.composer_enabled) return false
    if (state.value.status !== 'partial' && state.value.status !== 'missing') return false
    if (env.value?.not_allowed?.length) return false
    return Object.keys(installTargets.value).length > 0
  })

  const showManual = computed(() => {
    // 无法在线安装但有依赖需要装时，提供手动命令
    const hasTargets = Object.keys(installTargets.value).length > 0
    return hasTargets && !canInstall.value && !jobId.value
  })

  const manualCommand = ref('')

  const showReload = computed(() => {
    // 安装成功但未自动重载生效（Windows/无 posix）时提供兜底
    return runStatus.value === 'success' && doneOk.value === false
  })

  const buildManualCommand = () => {
    const specs = Object.entries(installTargets.value)
      .map(([pkg, cst]) => (cst ? `${pkg}:${cst}` : pkg))
      .map((s) => `"${s}"`)
      .join(' ')
    manualCommand.value = `composer require ${specs} -n --with-all-dependencies`
  }

  watch(
    () => props.modelValue,
    (visible) => {
      if (visible && props.plugin) {
        resetAll()
        runCheck()
      }
    }
  )

  const resetAll = () => {
    state.value = {}
    env.value = null
    jobId.value = ''
    channel.value = ''
    logLines.value = []
    shownCount.value = 0
    runStatus.value = ''
    doneOk.value = false
    doneMessage.value = ''
    manualCommand.value = ''
    stopPoll()
  }

  const runCheck = async () => {
    if (!props.plugin) return
    checking.value = true
    try {
      const res: any = await pluginApi.composerCheck({ id: props.plugin.id, name: props.plugin.name })
      state.value = res || {}
      env.value = res?.env || null
      buildManualCommand()
    } finally {
      checking.value = false
    }
  }

  const startInstall = async () => {
    if (!props.plugin || !canInstall.value) return
    installing.value = true
    runStatus.value = 'queued'
    try {
      const job: any = await pluginApi.composerInstall({
        id: props.plugin.id,
        name: props.plugin.name,
        targets: installTargets.value
      })
      jobId.value = job?.job_id || ''
      channel.value = job?.channel || ''
      // 轮询前先取一次历史快照回填，再接增量
      await pollOnce()
      startPoll()
    } catch {
      installing.value = false
    }
  }

  const startPoll = () => {
    stopPoll()
    // 轮询 composerStatus 快照：每次仅追加新行，实现终端式逐行输出；
    // push 通道不可用时此为可靠保底（后端同时逐行推 push，二者行序一致）。
    pollTimer = setInterval(pollOnce, 800)
  }

  const stopPoll = () => {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  const pollOnce = async () => {
    if (!jobId.value) return
    try {
      const snap: any = await pluginApi.composerStatus(jobId.value)
      if (!snap) return
      const log: string[] = Array.isArray(snap.log) ? snap.log : []
      if (log.length > shownCount.value) {
        appendLines(log.slice(shownCount.value))
        shownCount.value = log.length
      }
      if (snap.status) runStatus.value = snap.status
      if (snap.status === 'success' || snap.status === 'failed') {
        finishJob(snap)
      }
    } catch {
      // 单次轮询失败忽略，等待下次
    }
  }

  const appendLines = (lines: string[]) => {
    logLines.value.push(...lines)
    nextTick(() => {
      const el = terminalRef.value
      if (el) el.scrollTop = el.scrollHeight
    })
  }

  const finishJob = (snap: any) => {
    stopPoll()
    installing.value = false
    if (snap.status === 'success') {
      doneOk.value = Boolean(snap.reloaded)
      doneMessage.value = snap.reloaded
        ? '依赖安装完成，已平滑重载生效。'
        : snap.message || '依赖安装完成，但需手动重启服务生效。'
      emit('success', props.plugin as PluginRecord)
    } else {
      doneOk.value = false
      doneMessage.value = snap.message || `composer 执行失败（退出码 ${snap.exit_code ?? '未知'}）`
    }
    buildManualCommand()
  }

  const manualReload = async () => {
    reloading.value = true
    try {
      const res: any = await pluginApi.composerReload()
      if (res?.ok) {
        ElMessage.success(res.message || '已触发平滑重载')
        doneOk.value = true
      } else {
        ElMessage.warning(res?.message || '请手动重启服务使依赖生效')
      }
    } finally {
      reloading.value = false
    }
  }

  const copyManual = async () => {
    try {
      await navigator.clipboard.writeText(manualCommand.value)
      ElMessage.success('命令已复制')
    } catch {
      ElMessage.warning('复制失败，请手动选择命令复制')
    }
  }

  const handleVisibleChange = (value: boolean) => {
    if (installing.value && !value) return
    emit('update:modelValue', value)
  }

  const handleClosed = () => {
    stopPoll()
    installing.value = false
  }
</script>

<style scoped lang="scss">
  .composer-dialog {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .dialog-subtitle {
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .env-banner .env-detail {
    margin: 8px 0 0;
    padding-left: 18px;
    font-size: 12px;
    line-height: 1.9;
    color: var(--el-text-color-regular);
  }

  .env-path,
  .env-warn {
    color: var(--el-text-color-secondary);
  }

  .env-warn {
    color: var(--el-color-danger);
  }

  .block-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }

  .block-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--art-text-gray-900);
  }

  .package-table {
    width: 100%;
  }

  .terminal {
    max-height: 260px;
    padding: 12px;
    overflow-y: auto;
    font-family: 'Menlo', 'Consolas', 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.6;
    color: #d4d4d4;
    background: #1e1e1e;
    border-radius: 6px;
  }

  .terminal-line {
    white-space: pre-wrap;
    word-break: break-all;
  }

  .terminal-cursor {
    color: #4ec9b0;
    animation: blink 1s steps(2, start) infinite;
  }

  @keyframes blink {
    to {
      visibility: hidden;
    }
  }

  .done-message {
    margin-top: 8px;
    font-size: 12px;
  }

  .done-message.is-ok {
    color: var(--el-color-success);
  }

  .done-message.is-fail {
    color: var(--el-color-danger);
  }

  .manual-block {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
</style>
