<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="modelValue"
    @update:model-value="handleCancel"
    width="620px"
    align-center
    @closed="handleClosed"
  >
    <AmForm
      ref="formRef"
      v-model="formModel"
      :items="formItems"
      :rules="rules"
      :span="24"
      :gutter="20"
      label-width="110px"
      :show-reset="false"
      :show-submit="false"
    >
      <template #description>
        <ElInput
          v-model="form.description"
          type="textarea"
          :rows="3"
          placeholder="请输入插件描述"
        />
      </template>
      <template #name>
        <div class="name-check">
          <ElInput
            v-model="form.name"
            placeholder="如：todo"
            :disabled="props.type === 'edit'"
          >
            <template #prepend>am_</template>
            <template v-if="props.type !== 'edit'" #append>
              <ElButton :loading="nameChecking" @click="handleCheckName">检查</ElButton>
            </template>
          </ElInput>
          <div
            v-if="nameCheckMessage"
            class="name-check-tip"
            :class="nameCheckOk ? 'is-ok' : 'is-error'"
          >
            {{ nameCheckMessage }}
          </div>
        </div>
      </template>
    </AmForm>

    <!-- 会员登录能力：勾选后 manifest 声明 dependencies:["am_member"]，前台对接统一登录/注册/个人中心 -->
    <div class="member-auth">
      <div class="member-auth__label">会员登录能力</div>
      <div class="member-auth__body">
        <ElSwitch
          v-model="form.member_auth"
          :active-value="1"
          :inactive-value="0"
          :disabled="!memberAvailable"
        />
        <span class="member-auth__text">启用会员登录能力（依赖 am_member 会员中心）</span>
        <p class="member-auth__tip">
          {{
            memberAvailable
              ? '启用后插件将声明对 am_member 的依赖：未安装/未启用会员中心时插件无法被启用，禁用会员中心也会被反向拦截；前台可对接统一登录/注册/个人中心（需重载插件使 manifest 生效）。'
              : '未检测到已启用的会员中心 am_member，请先在「我的插件」安装并启用会员中心后再勾选。'
          }}
        </p>
      </div>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" @click="handleSubmit">确 定</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import type { FormRules } from 'element-plus'
  import type { FormItem } from '@/components/business/am-form/index.vue'
  import pluginApi from '@/api/plugin/app'

  interface Props {
    modelValue: boolean
    type: string
    editData?: Record<string, any>
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', data: Record<string, any>): void
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    type: 'add',
    editData: undefined
  })

  const emit = defineEmits<Emits>()
  const formRef = ref()

  const form = reactive<Record<string, any>>({
    id: 0,
    name: '',
    title: '',
    version: '1.0.0',
    author: '',
    icon: [],
    entry: '',
    manage_entry: '',
    type: 1,
    is_custom: 1,
    is_core: 0,
    source: 1,
    scope: 1,
    platform: 1,
    is_dev: 0,
    open_mode: 'micro',
    status: 0,
    description: '',
    // 表单专用：是否启用会员登录能力（提交时换算成 manifest.dependencies）
    member_auth: 0
  })

  // reactive 常量不能直接作为 v-model（编译出的赋值会抛错），
  // 用可写计算属性做一层合并，保证组件回写时也是原地修改
  const formModel = computed({
    get: () => form,
    set: (val: any) => Object.assign(form, val),
  })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入插件标识', trigger: 'blur' },
      {
        pattern: /^[a-z][a-z0-9_]*$/,
        message: '只能输入小写字母、数字和下划线，并以小写字母开头',
        trigger: 'blur'
      },
      { max: 77, message: '最多不能超过 77 个字符', trigger: 'blur' }
    ],
    title: [{ required: true, message: '请输入插件名称', trigger: 'blur' }],
    version: [{ required: true, message: '请输入插件版本', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => [
    { label: '插件标识', key: 'name' },
    { label: '插件名称', key: 'title', type: 'input', props: { placeholder: '请输入插件名称' } },
    { label: '版本', key: 'version', type: 'input', props: { placeholder: '如：1.0.0' } },
    { label: '作者', key: 'author', type: 'input', props: { placeholder: '请输入作者' } },
    {
      label: '应用入口',
      key: 'entry',
      type: 'input',
      props: { placeholder: '如：/apps/demo 或 https://example.com' }
    },
    {
      label: '管理入口',
      key: 'manage_entry',
      type: 'input',
      props: { placeholder: '默认使用应用入口，开发模式下建议直接填写本地url' }
    },
    {
      label: '应用图标',
      key: 'icon',
      type: 'image',
      props: {
        placeholder: '请选择应用图标',
        multiple: false,
        type: 1,
        displayData: iconDisplayData.value
      }
    },
    {
      label: '类型',
      key: 'type',
      type: 'dict',
      props: { dict: 'plugin_type', type: 'radiogroup', mode: 'border' }
    },
    {
      label: '来源',
      key: 'source',
      type: 'dict',
      props: { dict: 'plugin_source', type: 'radiogroup', mode: 'border' }
    },
    {
      label: '适用范围',
      key: 'scope',
      type: 'dict',
      props: { dict: 'plugin_scope', type: 'radiogroup', mode: 'border' }
    },
    {
      label: '使用设备',
      key: 'platform',
      type: 'dict',
      props: { dict: 'plugin_platform', type: 'radiogroup', mode: 'border' }
    },
    {
      label: '打开方式',
      key: 'open_mode',
      type: 'dict',
      props: { dict: 'plugin_open_mode', type: 'radiogroup', mode: 'border' }
    },
    {
      label: '核心应用',
      key: 'is_core',
      type: 'switch',
      props: { activeValue: 1, inactiveValue: 0 }
    },
    {
      label: '开发模式',
      key: 'is_dev',
      type: 'switch',
      props: { activeValue: 1, inactiveValue: 0 }
    },
    
    {
      label: '状态',
      key: 'status',
      type: 'dict',
      props: { dict: 'status', type: 'radiogroup', mode: 'border' }
    },
    { label: '描述', key: 'description' }
  ])

  const iconDisplayData = ref<Record<string, any>[]>([])

  // 会员中心是否已安装并启用（决定开关默认值与禁用态）
  const memberAvailable = ref(false)
  const MEMBER_PLUGIN = 'am_member'

  /** 拉取会员中心状态；不可达/未安装时开关保持禁用，不阻塞建插件 */
  const loadMemberStatus = async () => {
    try {
      const data = await pluginApi.readByName(MEMBER_PLUGIN)
      memberAvailable.value = Number((data as Record<string, any>)?.status) === 1
    } catch {
      memberAvailable.value = false
    }
  }

  const PLUGIN_PREFIX = 'am_'
  const getPluginSuffix = (name: string) => name.replace(new RegExp(`^${PLUGIN_PREFIX}`), '')
  const getPluginName = (suffix: string) => `${PLUGIN_PREFIX}${suffix}`

  // 应用标识检测（格式 + 不与市场冲突）
  const nameChecking = ref(false)
  const nameCheckOk = ref(false)
  const nameCheckMessage = ref('')

  const clearNameCheck = () => {
    nameChecking.value = false
    nameCheckOk.value = false
    nameCheckMessage.value = ''
  }

  const handleCheckName = async () => {
    const suffix = String(form.name || '').trim()
    if (!suffix) {
      nameCheckOk.value = false
      nameCheckMessage.value = '请先输入应用标识'
      return
    }
    if (!/^[a-z][a-z0-9_]*$/.test(suffix)) {
      nameCheckOk.value = false
      nameCheckMessage.value = '只能输入小写字母、数字和下划线，并以小写字母开头'
      return
    }
    if (suffix.length < 5) {
      nameCheckOk.value = false
      nameCheckMessage.value = '应用标识至少需 5 个字符（am_ 前缀之后的命名部分）'
      return
    }
    nameChecking.value = true
    nameCheckOk.value = false
    nameCheckMessage.value = ''
    try {
      const data = await pluginApi.checkName(getPluginName(suffix))
      nameCheckOk.value = !!data?.valid
      nameCheckMessage.value = data?.message || (data?.valid ? '标识可用' : '标识不可用')
    } catch (e: any) {
      nameCheckOk.value = false
      nameCheckMessage.value = e?.message || '检测失败，请稍后重试'
    } finally {
      nameChecking.value = false
    }
  }

  const dialogTitle = computed(() => (props.type === 'add' ? '新建插件' : '编辑插件'))

  const resetForm = () => {
    formRef.value?.reset()
    iconDisplayData.value = []
    clearNameCheck()
    Object.assign(form, {
      id: 0,
      name: '',
      title: '',
      version: '1.0.0',
      author: '',
      icon: [],
      entry: '',
      manage_entry: '',
      type: 1,
      is_custom: 1,
      is_core: 0,
      source: 1,
      scope: 1,
      platform: 1,
      is_dev: 0,
      open_mode: 'micro',
      status: 0,
      description: '',
      member_auth: 0
    })
  }

  const loadFormData = () => {
    if (!props.editData || Object.keys(props.editData).length === 0) return
    for (const key in form) {
      if (
        key !== 'icon' &&
        props.editData[key] !== null &&
        props.editData[key] !== undefined
      ) {
        form[key] = props.editData[key]
      }
    }
    form.name = getPluginSuffix(String(props.editData.name || ''))
    const manifest = props.editData.manifest || {}
    form.is_dev = Number(props.editData.is_dev ?? manifest.is_dev ?? 0)
    form.type = Number(props.editData.type ?? manifest.type ?? 1)
    form.is_core = Number(props.editData.is_core ?? manifest.is_core ?? 0)
    form.source = Number(props.editData.source ?? manifest.source ?? 1)
    form.scope = Number(props.editData.scope ?? manifest.scope ?? 1)
    form.platform = Number(props.editData.platform ?? manifest.platform ?? 1)
    form.entry = String(props.editData.entry || manifest.entry || '')
    form.manage_entry = String(props.editData.manage_entry || manifest.manage_entry || form.entry)
    const icon = String(props.editData.icon || '')
    if (icon) {
      iconDisplayData.value = [{ id: icon, url: '', path: icon, name: '应用图标' }]
      form.icon = [icon]
    }
    // 回显会员依赖：manifest.dependencies 优先，其次行数据 dependencies
    const depSource = props.editData.manifest?.dependencies ?? props.editData.dependencies
    form.dependencies = Array.isArray(depSource) ? depSource : []
    form.member_auth = (form.dependencies as string[]).includes(MEMBER_PLUGIN) ? 1 : 0
  }

  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
      const data = { ...form } as Record<string, any>
      data.name =
        props.type === 'edit'
          ? String(props.editData?.name || '')
          : getPluginName(String(data.name || ''))
      data.is_custom = 1
      data.is_core = Number(data.is_core || 0)
      data.type = Number(data.type || 1)
      data.source = Number(data.source || 1)
      data.scope = Number(data.scope || 1)
      data.platform = Number(data.platform || 1)
      data.is_dev = Number(data.is_dev || 0)
      data.open_mode = ['micro', 'iframe'].includes(data.open_mode) ? data.open_mode : 'micro'
      data.manage_entry = data.manage_entry || data.entry
      // 会员登录能力 → manifest.dependencies：保留既有的其它依赖，仅增删 am_member 一项
      const existingDeps = Array.isArray(data.dependencies)
        ? data.dependencies.filter((d: unknown) => d !== MEMBER_PLUGIN)
        : []
      data.dependencies = Number(data.member_auth) === 1 ? [...existingDeps, MEMBER_PLUGIN] : existingDeps
      delete data.member_auth
      if (props.type === 'add') {
        // 提交时二次校验：格式合法 + 不与市场冲突（市场不可达时交由后端再次把关）
        try {
          const checked = await pluginApi.checkName(data.name)
          nameCheckOk.value = !!checked?.valid
          nameCheckMessage.value = checked?.message || ''
          if (!checked?.valid) return
        } catch {
          /* 忽略，交由后端 save 再次校验 */
        }
        await pluginApi.save(data)
      } else {
        await pluginApi.update(data)
      }
      emit('success', data)
      handleCancel()
    } catch {
      // 表单校验未通过，无需额外处理
    }
  }

  const handleCancel = () => {
    emit('update:modelValue', false)
  }

  const handleClosed = () => {
    resetForm()
  }

  watch(
    () => form.name,
    (value) => {
      clearNameCheck()
      const pluginName = value ? getPluginName(value) : ''
      form.entry = pluginName ? `/app/${pluginName}` : ''
      form.manage_entry = pluginName ? `/app/${pluginName}/manage` : ''
    },
    { immediate: true, flush: 'sync' }
  )

  watch(
    () => props.modelValue,
    async (value: boolean) => {
      if (value) {
        await loadMemberStatus()
        resetForm()
        loadFormData()
        // 新建默认勾选（会员中心已启用时）；编辑以 manifest 现有 dependencies 回显为准
        if (props.type !== 'edit') {
          form.member_auth = memberAvailable.value ? 1 : 0
        }
      }
    }
  )
</script>

<style scoped lang="scss">
  .name-check-tip {
    margin-top: 4px;
    font-size: 12px;
    line-height: 1.4;

    &.is-ok {
      color: var(--el-color-success);
    }

    &.is-error {
      color: var(--el-color-danger);
    }
  }

  .icon-selector {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .icon-preview {
    width: 48px;
    height: 48px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .member-auth {
    display: flex;
    gap: 12px;
    margin-top: 4px;
    padding: 12px 14px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;

    &__label {
      flex-shrink: 0;
      font-size: 14px;
      color: var(--el-text-color-regular);
      white-space: nowrap;
    }

    &__body {
      display: flex;
      flex-wrap: wrap;
      gap: 6px 8px;
      align-items: center;
    }

    &__text {
      font-size: 14px;
      color: var(--el-text-color-primary);
    }

    &__tip {
      flex-basis: 100%;
      margin: 0;
      font-size: 12px;
      line-height: 1.5;
      color: var(--el-text-color-secondary);
    }
  }
</style>
