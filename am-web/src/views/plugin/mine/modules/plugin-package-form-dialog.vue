<template>
  <ElDialog
    :model-value="modelValue"
    title="生成安装包"
    width="720px"
    align-center
    :close-on-click-modal="!submitting"
    :close-on-press-escape="!submitting"
    @update:model-value="handleVisibleChange"
  >
    <div v-loading="loading" class="package-dialog">
      <div class="dialog-subtitle">
        确认发布配置后生成「{{ plugin?.title || plugin?.name }}」的安装包。
      </div>
      <ElAlert type="info" show-icon :closable="false" class="scope-alert">
        <template #title>
          <span class="scope-alert__text">
            生成前请先构建好前端文件。版本号、Composer 依赖与 Bridge权限会写回应用清单；应用入口、管理入口与核心应用标记只写入安装包，不影响正在开发的应用。包内固定按正式应用分发（开发标记归零）。
          </span>
        </template>
      </ElAlert>

      <AmForm
        ref="formRef"
        v-model="formModel"
        :items="formItems"
        :rules="rules"
        :span="24"
        :gutter="20"
        label-width="120px"
        :show-reset="false"
        :show-submit="false"
      >
        <template #composer>
          <div class="composer-rows">
            <div v-for="(row, index) in form.composer_rows" :key="index" class="composer-row">
              <ElInput v-model="row.package" placeholder="包名，如 overtrue/socialite" />
              <ElInput v-model="row.constraint" placeholder="版本约束，如 ^2.4" />
              <ElButton link type="danger" @click="removeComposerRow(index)">删除</ElButton>
            </div>
            <ElButton link type="primary" @click="addComposerRow">添加依赖</ElButton>
            <p class="field-tip">安装端按此声明安装依赖，留空行会被忽略；未声明则不安装。</p>
          </div>
        </template>

        <template #bridge_scopes>
          <div class="bridge-body">
            <div class="bridge-toolbar">
              <span class="bridge-count">
                已选 {{ form.bridge_scopes.length }} / {{ BRIDGE_SCOPE_OPTIONS.length }}
              </span>
              <div class="bridge-actions">
                <ElButton link type="primary" @click="selectAllScopes">全选</ElButton>
                <ElButton link @click="clearScopes">清空</ElButton>
              </div>
            </div>
            <ElCheckboxGroup v-model="form.bridge_scopes">
              <ElCheckbox
                v-for="scope in BRIDGE_SCOPE_OPTIONS"
                :key="scope.value"
                :value="scope.value"
                border
              >
                {{ scope.label }}
              </ElCheckbox>
            </ElCheckboxGroup>
            <p class="field-tip">
              未勾选时子应用仅能使用基础能力（登录态、字典、标题、消息、主题）；勾选后对应方法才会在生产环境放行。
            </p>
          </div>
        </template>
      </AmForm>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <ElButton :disabled="submitting" @click="handleVisibleChange(false)">取 消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">生成安装包</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type FormRules } from 'element-plus'
  import type { FormItem } from '@/components/business/am-form/index.vue'
  import { BRIDGE_SCOPE_OPTIONS } from '@/bridge/plugin/scopes'
  import { useUserStore } from '@/store/modules/user'
  import pluginApi from '@/api/plugin/app'
  import { getApiBaseURL } from '@/utils/env'
  import type { PluginRecord } from '@/utils/plugin'

  interface Props {
    modelValue: boolean
    plugin?: PluginRecord
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', plugin: PluginRecord): void
  }

  interface ComposerRow {
    package: string
    constraint: string
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()

  const userStore = useUserStore()
  const formRef = ref()
  const loading = ref(false)
  const submitting = ref(false)

  const form = reactive<Record<string, any>>({
    entry: '',
    manage_entry: '',
    is_core: 0,
    version: '1.0.0',
    composer_rows: [] as ComposerRow[],
    bridge_scopes: [] as string[]
  })

  // reactive 常量不能直接作为 v-model（编译出的赋值会抛错），
  // 用可写计算属性做一层合并，保证组件回写时也是原地修改
  const formModel = computed({
    get: () => form,
    set: (val: Record<string, any>) => Object.assign(form, val)
  })

  const rules = reactive<FormRules>({
    version: [{ required: true, message: '请输入版本号', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => [
    {
      label: '应用入口',
      key: 'entry',
      type: 'input',
      props: { placeholder: '如：/apps/demo 或 https://example.com，留空表示纯插件' }
    },
    {
      label: '管理入口',
      key: 'manage_entry',
      type: 'input',
      props: { placeholder: '留空则沿用应用入口' }
    },
    {
      label: '核心应用',
      key: 'is_core',
      type: 'switch',
      props: { activeValue: 1, inactiveValue: 0 }
    },
    {
      label: '版本号',
      key: 'version',
      type: 'input',
      props: { placeholder: '如：1.0.0' }
    },
    { label: 'Composer 依赖', key: 'composer', span: 24 },
    { label: 'Bridge 权限', key: 'bridge_scopes', span: 24 }
  ])

  const addComposerRow = () => {
    form.composer_rows.push({ package: '', constraint: '' })
  }

  const removeComposerRow = (index: number) => {
    form.composer_rows.splice(index, 1)
  }

  const selectAllScopes = () => {
    form.bridge_scopes = BRIDGE_SCOPE_OPTIONS.map((scope) => scope.value)
  }

  const clearScopes = () => {
    form.bridge_scopes = []
  }

  const resetForm = () => {
    // 先复位校验状态（AmForm 会把模型还原为挂载时的初值），再写入本弹窗的默认值
    formRef.value?.reset()
    Object.assign(form, {
      entry: '',
      manage_entry: '',
      is_core: 0,
      version: '1.0.0',
      composer_rows: [],
      bridge_scopes: []
    })
  }

  /** 查询应用信息回显：入口/核心标记取当前清单，版本、依赖与权限同样来自清单。 */
  const loadForm = async () => {
    if (!props.plugin?.id) return
    loading.value = true
    try {
      const info: any = await pluginApi.read(Number(props.plugin.id))
      const manifest = info?.manifest || {}
      form.entry = String(info?.entry || manifest.entry || '')
      form.manage_entry = String(info?.manage_entry || manifest.manage_entry || '')
      form.is_core = Number(info?.is_core ?? manifest.is_core ?? 0)
      form.version = String(info?.version || manifest.version || '1.0.0')
      const requires = manifest.composer?.require || {}
      form.composer_rows = Object.keys(requires).map((pkg) => ({
        package: pkg,
        constraint: String(requires[pkg] ?? '')
      }))
      form.bridge_scopes = Array.isArray(manifest.bridge?.scopes) ? [...manifest.bridge.scopes] : []
    } finally {
      loading.value = false
    }
  }

  /** 表单 → 提交体：空包名行丢弃，包名重复直接拦下（后端按对象合并会静默覆盖）。 */
  const buildPayload = (): Record<string, any> | null => {
    const require: Record<string, string> = {}
    const names: string[] = []
    for (const row of form.composer_rows as ComposerRow[]) {
      const pkg = String(row.package || '').trim()
      if (!pkg) continue
      if (names.includes(pkg)) {
        ElMessage.warning(`Composer 包名重复：${pkg}`)
        return null
      }
      names.push(pkg)
      require[pkg] = String(row.constraint || '').trim() || '*'
    }

    const entry = String(form.entry || '').trim()
    return {
      id: props.plugin?.id,
      entry,
      manage_entry: String(form.manage_entry || '').trim() || entry,
      is_core: Number(form.is_core || 0) === 1 ? 1 : 0,
      version: String(form.version || '').trim(),
      composer: { require },
      bridge_scopes: [...form.bridge_scopes]
    }
  }

  /** 接口回 JSON 即为失败（后端 warning / ApiException），否则按二进制流落盘。 */
  const downloadPackage = async (payload: Record<string, any>) => {
    const response = await fetch(`${getApiBaseURL()}/plugin/app/package`, {
      method: 'POST',
      credentials: import.meta.env.VITE_WITH_CREDENTIALS === 'true' ? 'include' : 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(userStore.accessToken ? { Authorization: userStore.accessToken } : {})
      },
      body: JSON.stringify(payload)
    })

    const contentType = response.headers.get('Content-Type') || ''
    if (!response.ok || contentType.includes('application/json')) {
      const result = await response.json().catch(() => null)
      ElMessage.error(result?.msg || result?.message || '生成安装包失败')
      return false
    }

    const blob = await response.blob()
    const disposition = response.headers.get('Content-Disposition') || ''
    const fileName = disposition.match(/filename="?([^";]+)"?/)?.[1] || `plugin_${payload.id}.zip`
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = fileName
    link.click()
    URL.revokeObjectURL(url)
    return true
  }

  const handleSubmit = async () => {
    if (!props.plugin || submitting.value) return
    try {
      await formRef.value?.validate()
    } catch {
      ElMessage.warning('请先完善表单必填项')
      return
    }

    const payload = buildPayload()
    if (!payload) return

    const plugin = props.plugin
    submitting.value = true
    try {
      if (await downloadPackage(payload)) {
        ElMessage.success('安装包已生成')
        emit('success', plugin)
        // 请求期间 submitting 为 true 会拦住关闭，成功后直接派发关闭事件
        emit('update:modelValue', false)
      }
    } catch {
      ElMessage.error('生成安装包失败')
    } finally {
      submitting.value = false
    }
  }

  const handleVisibleChange = (value: boolean) => {
    if (submitting.value && !value) return
    emit('update:modelValue', value)
  }

  watch(
    () => props.modelValue,
    async (value) => {
      resetForm()
      if (value) await loadForm()
    }
  )
</script>

<style scoped lang="scss">
  .package-dialog {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .dialog-subtitle {
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .scope-alert__text {
    font-size: 12px;
    font-weight: 400;
    line-height: 1.6;
  }

  .composer-rows {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
    width: 100%;
  }

  .composer-row {
    display: flex;
    gap: 8px;
    align-items: center;
    width: 100%;
  }

  .bridge-body {
    width: 100%;

    // 带边框的多选项默认只留右间距，换行后上下会贴在一起，改用 flex 统一行/列间距
    :deep(.el-checkbox-group) {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 12px;
    }

    :deep(.el-checkbox.is-bordered) {
      margin-right: 0;
    }

    .field-tip {
      margin-top: 6px;
    }
  }

  .bridge-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 8px;
  }

  .bridge-count {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .bridge-actions {
    display: flex;
    gap: 4px;
  }

  .field-tip {
    margin: 0;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }
</style>
