<template>
  <ElDialog
    :title="`测试发送 - ${templateData?.name || ''}`"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="600px"
    align-center
    @closed="handleClosed"
  >
    <!-- 接收方信息 -->
    <div class="test-section">
      <div class="test-row">
        <span class="test-label">接收邮箱</span>
        <ElInput v-model="testEmail" placeholder="接收邮箱" clearable />
      </div>
      <div class="test-row">
        <span class="test-label">接收手机</span>
        <ElInput v-model="testPhone" placeholder="接收手机号" clearable />
      </div>
    </div>

    <!-- 变量填写 -->
    <el-divider content-position="left" v-if="variables.length > 0">模板变量</el-divider>
    <div class="test-section" v-if="variables.length > 0">
      <div v-for="(item, index) in variables" :key="index" class="test-row">
        <span class="test-label" :title="item.name">{{ item.label || item.name }}</span>
        <ElInput v-model="item.test_value" :placeholder="`{{${item.name}}}`" clearable />
      </div>
    </div>

    <!-- 发送结果 -->
    <el-divider content-position="left" v-if="showResult">发送结果</el-divider>
    <div class="test-section" v-if="showResult">
      <div v-for="(result, channel) in testResults" :key="channel" class="test-result-row">
        <ElTag :type="result.success ? 'success' : 'danger'" size="small">
          {{ channelLabelMap[channel as string] || channel }}
        </ElTag>
        <span :class="result.success ? 'text-success' : 'text-danger'">
          {{ result.message }}
        </span>
      </div>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">关 闭</ElButton>
        <ElButton type="primary" :loading="sending" @click="handleSend">发送测试</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import notifyApi from '@/api/notify'

  interface Props {
    visible: boolean
    templateData?: any
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
  })

  const emit = defineEmits<Emits>()

  const userStore = useUserStore()
  const sending = ref(false)
  const showResult = ref(false)
  const testResults = ref<Record<string, { success: boolean; message: string }>>({})
  const testEmail = ref('')
  const testPhone = ref('')
  const variables = ref<{ name: string; label: string; test_value: string }[]>([])

  const channelLabelMap: Record<string, string> = {
    email: '邮件',
    inbox: '站内信',
    sms: '短信',
  }

  const resetState = () => {
    sending.value = false
    showResult.value = false
    testResults.value = {}
    variables.value = []
  }

  const loadTemplateData = async () => {
    if (!props.templateData) return

    // 预填当前用户邮箱和手机
    const userInfo = userStore.getUserInfo as any
    testEmail.value = userInfo?.email || ''
    testPhone.value = userInfo?.mobile || ''

    // 从 read 接口获取完整数据（含 variables）
    let tpl: any = props.templateData
    if (tpl.id) {
      try {
        const res = await notifyApi.template.read(tpl.id)
        tpl = res || tpl
      } catch (e) {
        // 获取详情失败时使用列表行数据
      }
    }

    // 加载模板变量
    let vars = tpl.variables
    if (typeof vars === 'string') {
      try { vars = JSON.parse(vars) } catch { vars = null }
    }
    if (vars && typeof vars === 'object' && !Array.isArray(vars)) {
      vars = Object.values(vars)
    }
    if (Array.isArray(vars) && vars.length > 0) {
      variables.value = vars.map((v: any) => ({
        name: v.name || '',
        label: v.label || '',
        test_value: v.test_value || '',
      }))
    }
  }

  const handleSend = async () => {
    if (!props.templateData?.id) return

    sending.value = true
    showResult.value = false

    try {
      const res = await notifyApi.template.test({
        id: props.templateData.id,
        email: testEmail.value,
        phone: testPhone.value,
        variables: variables.value,
      })
      testResults.value = res || {}
      showResult.value = true
    } catch (error) {
      // 拦截器已处理错误提示
    } finally {
      sending.value = false
    }
  }

  const handleCancel = () => {
    emit('update:visible', false)
  }

  const handleClosed = () => {
    resetState()
  }

  watch(
    () => [props.visible, props.templateData],
    ([visible]) => {
      if (visible) {
        resetState()
        loadTemplateData()
      }
    }
  )
</script>

<style scoped lang="scss">
  .test-section {
    padding: 0 10px;
  }

  .test-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;

    .test-label {
      flex-shrink: 0;
      width: 100px;
      text-align: right;
      font-size: 14px;
      color: var(--el-text-color-regular);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .test-result-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
  }

  .text-success {
    color: var(--el-color-success);
  }

  .text-danger {
    color: var(--el-color-danger);
  }
</style>
