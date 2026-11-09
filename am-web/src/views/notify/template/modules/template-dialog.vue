<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="750px"
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
      label-width="140px"
      :show-reset="false"
      :show-submit="false"
    />

    <!-- 变量声明区域 -->
    <el-divider content-position="left">变量声明</el-divider>
    <div class="variables-section">
      <div v-for="(item, index) in variablesList" :key="index" class="variable-row">
        <ElInput v-model="item.name" placeholder="变量名（如 username）" style="width: 200px" />
        <ElInput v-model="item.label" placeholder="变量说明（如 用户名）" style="flex: 1" />
        <ElInput v-model="item.test_value" placeholder="预览测试值" style="flex: 1" />
        <ElButton type="danger" text @click="removeVariable(index)">
          <Icon icon="ri:delete-bin-line" />
        </ElButton>
      </div>
      <ElButton type="primary" text @click="addVariable">
        <Icon icon="ri:add-line" /> 添加变量
      </ElButton>
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
  import { ref, reactive, computed } from 'vue'
  import type { FormRules } from 'element-plus'
  import { Icon } from '@iconify/vue'
  import notifyApi from '@/api/notify'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  interface Props {
    visible: boolean
    type: string
    editData?: any
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit'): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    type: 'add'
  })

  const emit = defineEmits<Emits>()

  const variablesList = ref<{ name: string; label: string; test_value: string }[]>([])

  const form = reactive<any>({
    id: 0,
    name: '',
    code: '',
    source: 'system',
    sms_provider_id: '',
    title: '',
    content: '',
    sms_template_id: '',
    sms_content: '',
    status: 1,
    remark: ''
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: notifyApi.template,
      emitData: false,
      resetOnCancel: true,
      titleText: (e) => (e ? '编辑通知模板' : '新建通知模板'),
      resetFields: () => {
        form.id = 0
        form.name = ''
        form.code = ''
        form.source = 'system'
        form.sms_provider_id = ''
        form.title = ''
        form.content = ''
        form.sms_template_id = ''
        form.sms_content = ''
        form.status = 1
        form.remark = ''
        variablesList.value = []
      },
      beforeWatchLoad: () => {
        form.id = 0
      },
      afterLoad: (row) => {
        // 加载变量声明
        let vars = row.variables
        if (typeof vars === 'string') {
          try {
            vars = JSON.parse(vars)
          } catch {
            vars = null
          }
        }
        if (vars && typeof vars === 'object' && !Array.isArray(vars)) {
          vars = Object.values(vars)
        }
        if (Array.isArray(vars) && vars.length > 0) {
          variablesList.value = vars.map((v: any) => ({
            name: v.name || '',
            label: v.label || '',
            test_value: v.test_value || ''
          }))
        }
      },
      buildPayload: () => {
        // 过滤空变量
        const validVars = variablesList.value.filter((v) => v.name.trim() !== '')
        return { ...form, variables: validVars }
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入模板名称', trigger: 'blur' },
      { min: 2, max: 100, message: '长度在 2 到 100 个字符', trigger: 'blur' }
    ],
    code: [
      { required: true, message: '请输入模板编码', trigger: 'blur' },
      { max: 50, message: '模板编码最多50个字符', trigger: 'blur' }
    ]
  })

  const formItems = computed(() => [
    { label: '模板名称', key: 'name', type: 'input', props: { placeholder: '如：注册验证码' } },
    {
      label: '模板编码',
      key: 'code',
      type: 'input',
      props: {
        placeholder: '如：register_code',
        disabled: props.type === 'edit'
      }
    },
    { label: '模板来源', key: 'source', type: 'input', props: { placeholder: 'system 或插件名' } },
    {
      label: '短信服务商',
      key: 'sms_provider_id',
      type: 'remote',
      props: {
        remote: 'sms_provider',
        type: 'select',
        placeholder: '选择服务商（可留空用默认）',
        clearable: true
      }
    },
    {
      label: '短信模板ID',
      key: 'sms_template_id',
      type: 'input',
      props: { placeholder: '服务商模板编号（如 SMS_123456），留空则用下方短信内容发送' }
    },
    {
      label: '短信内容',
      key: 'sms_content',
      type: 'input',
      props: {
        placeholder: '纯文本短信内容，支持 {{变量名}}。如未填写短信模板ID则用此内容发送',
        type: 'textarea',
        maxlength: 500,
        showWordLimit: true
      }
    },
    {
      label: '标题',
      key: 'title',
      type: 'input',
      props: { placeholder: '邮件主题 / 站内信标题，支持 {{变量名}}' }
    },
    {
      label: '模板内容',
      key: 'content',
      type: 'editor',
      props: {
        placeholder: '邮件/站内信的富文本内容，支持 {{变量名}}',
        height: '280px'
      }
    },
    {
      label: '备注',
      key: 'remark',
      type: 'input',
      props: { placeholder: '备注信息', type: 'textarea' }
    },
    {
      label: '状态',
      key: 'status',
      type: 'dict',
      props: { dict: 'status', type: 'radio', mode: 'border' }
    }
  ])

  const addVariable = () => {
    variablesList.value.push({ name: '', label: '', test_value: '' })
  }

  const removeVariable = (index: number) => {
    variablesList.value.splice(index, 1)
  }
</script>

<style scoped lang="scss">
  .variables-section {
    padding: 0 10px;

    .variable-row {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
    }
  }
</style>
