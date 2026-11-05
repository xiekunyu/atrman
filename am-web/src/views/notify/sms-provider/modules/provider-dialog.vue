<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="500px"
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
      label-width="150px"
      :show-reset="false"
      :show-submit="false"
    />

    <el-divider content-position="left" v-if="form.type">服务商配置</el-divider>

    <AmForm
      ref="formRefs"
      v-model="formData"
      :items="formConfig.items"
      :rules="formConfig.rules"
      :span="24"
      :gutter="20"
      label-width="150px"
      :show-submit="false"
      :show-reset="false"
    />

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" @click="handleSubmit">确 定</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, reactive, computed, watch } from 'vue'
  import type { FormRules } from 'element-plus'
  import configApi from '@/api/system/config'
  import smsProviderApi from '@/api/notify/sms-provider'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { buildFormConfig } from '@/utils/custom/index'
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

  const formRefs = ref()
  const configList = ref<any>([])
  const formData = ref<any>({})
  const formConfig = ref<any>({})

  // 基本信息表单数据
  const form = reactive<any>({
    id: 0,
    name: '',
    type: '',
    status: 1,
    remark: ''
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: smsProviderApi,
      emitData: false,
      resetOnCancel: true,
      resetFields: () => {
        form.id = 0
        form.type = ''
      },
      afterLoad: (row) => {
        formData.value = row.config || {}
      },
      beforeSubmit: async () => {
        // 如果有配置表单，也验证
        if (formRefs.value && formConfig.value?.items?.length > 0) {
          await formRefs.value.validate()
        }
        // 合并配置数据
        Object.assign(form, { config: formData.value })
      },
      afterClosed: () => {
        formData.value = {}
        formConfig.value = {}
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入供应商名称', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ],
    type: [{ required: true, message: '请选择服务商类型', trigger: 'change' }]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '服务商名称', key: 'name', type: 'input', props: { placeholder: '请输入名称' } },
      {
        label: '服务商类型',
        key: 'type',
        type: 'remote',
        props: { remote: 'sms_provider_config', type: 'select', placeholder: '请选择服务商类型' }
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
    ]
  })

  /** 加载配置表单（根据选中的服务商类型） */
  const loadConfig = async (row: any) => {
    const items = [] as any[]
    const config = {} as any
    try {
      const res = await configApi.getConfig({ id: row.id })
      configList.value = res.list || []

      if (configList.value.length > 0) {
        configList.value.forEach((item: any) => {
          const options = item?.options || []
          Object.assign(options, { id: item.id, displayData: item.displayData || [] })
          items.push(options)
          config[item.code] = item.value
        })
      }
      formConfig.value = buildFormConfig(items)
      if (props.type === 'add') {
        formData.value = config
      }
    } catch (error) {
      console.error('获取配置异常:', error)
    }
  }

  /** 监听服务商类型变化，加载对应配置表单 */
  watch(
    () => form.type,
    (newVal) => {
      if (newVal) {
        loadConfig({ id: newVal })
      }
    }
  )
</script>
