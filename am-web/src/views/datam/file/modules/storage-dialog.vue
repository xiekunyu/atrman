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
    >
    </AmForm>

    <el-divider content-position="left" v-if="form.type">储存策略配置</el-divider>

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
  import storageApi from '@/api/system/storage'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { buildFormConfig } from '@/utils/custom/index'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  interface UserFormData {
    id: number
    name: string
    status: number
  }

  interface Props {
    visible: boolean
    type: string
    editData?: any
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: UserFormData): void
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

  const form = reactive<any>({
    id: 0,
    name: '',
    type: '',
    status: 1
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: storageApi,
      resetOnCancel: true,
      resetFields: () => {
        form.id = 0
      },
      afterLoad: (row) => {
        formData.value = row.config || {}
      },
      beforeSubmit: async () => {
        if (formRefs.value) {
          await formRefs.value.validate()
        }
        // 将两个表单数据合并到 form 中
        Object.assign(form, { config: formData.value })
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入名称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    type: [{ required: true, message: '请选择存储策略', trigger: 'change' }]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '名称', key: 'name', type: 'input', props: { placeholder: '请输入名称' } },
      {
        label: '存储策略',
        key: 'type',
        type: 'remote',
        props: { remote: 'storage_config', type: 'select', placeholder: '请选择存储策略' }
      },
      {
        label: '状态',
        key: 'status',
        type: 'dict',
        props: { dict: 'status', type: 'radio', mode: 'border' }
      }
    ]
  })

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
      // 先更新 formItems，再基于新的 formItems 构建 formConfig
      formConfig.value = buildFormConfig(items)
      if (props.type === 'add') {
        formData.value = config
      }
    } catch (error) {
      console.error('获取配置异常:', error)
    }
  }

  watch(
    () => form.type,
    (newVal) => {
      if (newVal) {
        loadConfig({ id: newVal })
      }
    }
  )
</script>
