<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="modelValue"
    @update:model-value="handleCancel"
    width="560px"
    align-center
    @closed="handleClosed"
  >
    <div class="mb-4">
      <ElSegmented v-model="packageMode" class="package-mode" :options="packageModeOptions" />
    </div>

    <div class="package-selector">
      <ElButton type="primary" plain @click="fileDialogVisible = true">{{ selectButtonText }}</ElButton>
      <span class="package-name">{{ selectedFile?.name || packagePlaceholder }}</span>
    </div>

    <div class="mt-3">
      <ElAlert
        class="mt-3"
        type="info"
        show-icon
        :closable="false"
        :title="packageAlertTitle"
      />
    </div>
    <div v-if="packageMode === 'install' && inspectLoading" class="inspect-loading">正在预检插件包...</div>

    <div v-if="packageMode === 'install' && inspectInfo" class="inspect-panel">
      <ElAlert
        class="inspect-summary"
        :type="inspectInfo.can_install === false ? 'error' : 'success'"
        show-icon
        :closable="false"
        :title="inspectInfo.can_install === false ? '预检未通过' : '预检通过'"
        :description="inspectSummaryDescription"
      />
      <div v-if="autoDisableText" class="auto-disable">{{ autoDisableText }}</div>
      <div class="inspect-row">
        <span>插件</span>
        <strong>{{ inspectInfo.plugin?.title || '-' }}（{{ inspectInfo.plugin?.name || '-' }}）</strong>
      </div>
      <div class="inspect-row">
        <span>安装包版本</span>
        <strong>{{ inspectInfo.plugin?.version || '-' }}</strong>
      </div>
      <div class="inspect-row">
        <span>当前版本</span>
        <strong>{{ inspectInfo.existing?.version || '未安装' }}</strong>
      </div>
      <div class="inspect-row">
        <span>操作类型</span>
        <ElTag :type="operationTagType">{{ operationText }}</ElTag>
      </div>
      <div v-if="inspectInfo.dependency_info?.length" class="inspect-block">
        <div class="inspect-block-title">依赖检查</div>
        <div v-for="dependency in inspectInfo.dependency_info" :key="dependency.name" class="dependency-row">
          <span>{{ dependency.title || dependency.name }}</span>
          <ElTag :type="dependency.satisfied ? 'success' : 'danger'" size="small">
            {{ dependency.satisfied ? '正常' : '异常' }}
          </ElTag>
        </div>
      </div>
      <!-- 待迁移为 0 时整块没有信息量，不显示；已执行为 0 时只隐藏该行 -->
      <div v-if="inspectInfo.migration_info?.pending_count > 0" class="inspect-block">
        <div class="inspect-block-title">迁移检查</div>
        <div class="inspect-row">
          <span>待执行迁移</span>
          <ElTag type="warning" size="small">{{ inspectInfo.migration_info.pending_count }} 个</ElTag>
        </div>
        <div v-if="inspectInfo.migration_info.applied_count > 0" class="inspect-row">
          <span>已执行迁移</span>
          <ElTag type="success" size="small">{{ inspectInfo.migration_info.applied_count }} 个</ElTag>
        </div>
        <div v-if="inspectInfo.migration_info.pending?.length" class="migration-list">
          <div
            v-for="migration in inspectInfo.migration_info.pending"
            :key="migration.migration"
            class="migration-row"
          >
            <span>{{ migration.migration }}</span>
            <ElTag size="small" type="warning">{{ migration.version }}</ElTag>
          </div>
        </div>
      </div>
      <div class="mb-3">
      <ElAlert
        v-for="error in inspectInfo.errors || []"
        :key="error"
        class="dependency-alert"
        type="error"
        show-icon
        :closable="false"
        :title="error"
      />
      </div>
      <div class="mb-3">
      <ElAlert
        v-for="warning in inspectInfo.warnings || []"
        :key="warning"
        class="dependency-alert"
        type="warning"
        show-icon
        :closable="false"
        :title="warning"
      />
      </div>
    </div>

    <ElCheckbox
      v-if="packageMode === 'install'"
      v-model="overwrite"
      class="mt-4"
      :disabled="!inspectInfo?.requires_overwrite"
    >
      我了解本操作会替换插件目录；升级时将执行待迁移 SQL，且不支持降级
    </ElCheckbox>
    <ElCheckbox v-else v-model="restoreConfirmed" class="mt-4">
      我确认将执行备份包 restore.sql，可能覆盖插件菜单、配置、小部件和业务表数据
    </ElCheckbox>

    <AmFileSelection
      v-model="fileDialogVisible"
      :title="fileDialogTitle"
      :multiple="false"
      :type="5"
      accept=".zip"
      :default-value="selectedFile ? [selectedFile.id] : []"
      :display-data="selectedFile ? [selectedFile] : []"
      @confirm="handleFileConfirm"
      @cancel="fileDialogVisible = false"
    />

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" :loading="loading" :disabled="submitDisabled" @click="handleSubmit">
          {{ submitText }}
        </ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessageBox } from 'element-plus'
  import pluginApi from '@/api/plugin/app'
  import AmFileSelection from '@/components/business/am-file-selection/index.vue'

  interface Props {
    modelValue: boolean
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success'): void
  }

  withDefaults(defineProps<Props>(), {
    modelValue: false
  })

  const emit = defineEmits<Emits>()
  const packageModeOptions = [
    { label: '安装/升级', value: 'install' },
    { label: '恢复', value: 'restore' }
  ]
  const packageMode = ref<'install' | 'restore'>('install')
  const fileDialogVisible = ref(false)
  const selectedFile = ref<Record<string, any>>()
  const overwrite = ref(false)
  const restoreConfirmed = ref(false)
  const loading = ref(false)
  const inspectLoading = ref(false)
  const inspectInfo = ref<Record<string, any>>()

  const isZipFile = (file: Record<string, any>) =>
    String(file.ext || '').toLowerCase() === 'zip' ||
    /\.zip$/i.test(String(file.name || file.path || ''))

  const operationText = computed(() => {
    const map: Record<string, string> = {
      install: '安装',
      update: '升级',
      overwrite: '覆盖安装',
      downgrade: '降级被阻止'
    }
    return map[String(inspectInfo.value?.operation || 'install')] || '安装'
  })

  const operationTagType = computed(() => {
    const map: Record<string, 'success' | 'warning' | 'info' | 'danger'> = {
      install: 'success',
      update: 'warning',
      overwrite: 'info',
      downgrade: 'danger'
    }
    return map[String(inspectInfo.value?.operation || 'install')] || 'success'
  })

  const dialogTitle = computed(() =>
    packageMode.value === 'restore' ? '恢复插件备份' : `${operationText.value}插件包`
  )
  const submitText = computed(() => (packageMode.value === 'restore' ? '恢复' : operationText.value))
  const selectButtonText = computed(() => (packageMode.value === 'restore' ? '选择备份包' : '选择插件包'))
  const packagePlaceholder = computed(() =>
    packageMode.value === 'restore' ? '请选择 zip 插件备份包' : '请选择 zip 插件安装包'
  )
  const fileDialogTitle = computed(() =>
    packageMode.value === 'restore' ? '选择插件备份 zip' : '选择插件 zip 安装包'
  )
  const packageAlertTitle = computed(() =>
    packageMode.value === 'restore'
      ? '恢复只会执行备份包根目录 restore.sql，不会执行 install.sql 或 migrations。请确认备份包来源可信，并已了解数据覆盖风险。'
      : '预检会校验 manifest、依赖与基座版本兼容性；升级启用中的插件无需先手动禁用。'
  )
  /** 一句话说明本次动作差异，并入预检结论描述，不再单独占一条橙色告警 */
  const operationTip = computed(() => {
    const operation = String(inspectInfo.value?.operation || '')
    if (operation === 'install') return '首次安装将执行 install.sql。'
    if (operation === 'overwrite') return '同版本覆盖不执行 install.sql 与迁移。'
    if (operation === 'update') return '将覆盖插件目录，并执行待迁移 SQL。'
    return ''
  })
  /** 自动停用范围（后端与市场升级共用同一编排给出）：目标启用中或存在启用中的依赖方时列出 */
  const autoDisableText = computed(() => {
    const auto = inspectInfo.value?.auto_disable
    if (!auto?.needed) return ''
    const names = [
      ...(auto.target_was_enabled ? [auto.target_title || inspectInfo.value?.plugin?.title || '-'] : []),
      ...(auto.will_disable || []).map((item: Record<string, any>) => item.title || item.name)
    ]
    return `将自动停用「${names.join('」「')}」，${operationText.value}完成后自动恢复。`
  })
  const inspectSummaryDescription = computed(() => {
    if (!inspectInfo.value) return ''
    if (inspectInfo.value.can_install === false) {
      return '请先处理下方阻断项，再重新选择安装包或重试。'
    }
    return operationTip.value || '安装包满足当前系统要求，可继续。'
  })
  const submitDisabled = computed(() => {
    if (packageMode.value === 'restore') {
      return !selectedFile.value || loading.value || !restoreConfirmed.value
    }
    return (
      !selectedFile.value ||
      inspectLoading.value ||
      inspectInfo.value?.can_install === false ||
      (inspectInfo.value?.requires_overwrite && !overwrite.value)
    )
  })

  const handleFileConfirm = async (files: Record<string, any>[]) => {
    const file = files[0]
    if (!file) return
    if (!isZipFile(file)) {
      ElMessage.warning(packageMode.value === 'restore' ? '请选择 zip 插件备份包' : '请选择 zip 插件安装包')
      return
    }
    selectedFile.value = file
    overwrite.value = false
    restoreConfirmed.value = false
    fileDialogVisible.value = false
    if (packageMode.value === 'restore') {
      inspectInfo.value = undefined
      return
    }
    await inspectPackage(file)
  }

  const inspectPackage = async (file: Record<string, any>) => {
    inspectLoading.value = true
    inspectInfo.value = undefined
    try {
      inspectInfo.value = await pluginApi.packageInspect({ file_id: file.id })
      overwrite.value = !inspectInfo.value?.requires_overwrite
    } finally {
      inspectLoading.value = false
    }
  }

  const handleSubmit = async () => {
    if (!selectedFile.value) {
      ElMessage.warning(packageMode.value === 'restore' ? '请选择插件备份包' : '请选择插件安装包')
      return
    }
    if (!isZipFile(selectedFile.value)) {
      ElMessage.warning(packageMode.value === 'restore' ? '请选择 zip 插件备份包' : '请选择 zip 插件安装包')
      return
    }
    if (submitDisabled.value) {
      return
    }

    if (packageMode.value === 'restore') {
      try {
        await ElMessageBox.confirm(
          '确定执行该备份包中的 restore.sql 吗？本操作不会执行 install.sql 或 migrations，但可能覆盖相关数据。',
          '恢复确认',
          { type: 'warning' }
        )
      } catch {
        return
      }
    }

    loading.value = true
    try {
      if (packageMode.value === 'restore') {
        await pluginApi.restorePackage({ file_id: selectedFile.value.id })
      } else {
        const res: any = await pluginApi.installPackage({
          file_id: selectedFile.value.id,
          overwrite: overwrite.value ? 1 : 0
        })
        // 升级失败已自动回滚时后端返回 code=400 + ok:false（拦截器只弹提示，不抛错）：
        // 保持弹窗打开并重跑预检，让用户看到回滚后的真实状态，而不是直接关掉
        if (res?.ok === false) {
          emit('success')
          await inspectPackage(selectedFile.value)
          return
        }
      }
      emit('success')
      handleCancel()
    } finally {
      loading.value = false
    }
  }

  const handleCancel = () => {
    emit('update:modelValue', false)
  }

  watch(packageMode, () => {
    selectedFile.value = undefined
    inspectInfo.value = undefined
    overwrite.value = false
    restoreConfirmed.value = false
    inspectLoading.value = false
  })

  const handleClosed = () => {
    packageMode.value = 'install'
    selectedFile.value = undefined
    inspectInfo.value = undefined
    overwrite.value = false
    restoreConfirmed.value = false
    inspectLoading.value = false
    fileDialogVisible.value = false
  }
</script>

<style scoped lang="scss">
  .package-selector {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .package-name {
    min-width: 0;
    overflow: hidden;
    color: var(--el-text-color-regular);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .inspect-loading {
    margin-top: 16px;
    color: var(--el-text-color-secondary);
  }

  .inspect-panel {
    padding: 12px;
    margin-top: 16px;
    background: var(--el-fill-color-lighter);
    border-radius: 6px;
  }

  .inspect-summary {
    margin-bottom: 12px;
  }

  .auto-disable {
    margin-bottom: 10px;
    color: var(--el-color-warning);
    font-size: 12px;
    line-height: 1.6;
  }

  .migration-list {
    padding-top: 4px;
  }

  .inspect-row,
  .dependency-row,
  .migration-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 28px;
    margin-bottom: 8px;
  }

  .dependency-alert {
    margin-bottom: 8px;
  }

  .inspect-row span,
  .migration-row span,
  .inspect-block-title {
    color: var(--el-text-color-secondary);
  }

  .inspect-block {
    padding-top: 8px;
    margin-top: 8px;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  .inspect-block-title {
    margin-bottom: 6px;
    font-size: 12px;
  }
</style>
