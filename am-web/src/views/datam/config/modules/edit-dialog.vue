<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="30%"
    align-center
    class="menu-dialog"
    @closed="handleClosed"
  >
    <ArtForm
      ref="formRef"
      v-model="formModel"
      :items="formItems"
      :rules="rules"
      :span="24"
      :gutter="20"
      label-width="100px"
      :show-reset="false"
      :show-submit="false"
    >
      <template #color>
        <ElColorPicker v-model="form.color" />
      </template>
    </ArtForm>

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
  import configApi from '@/api/system/config'
  import type { AppRouteRecord } from '@/types/router'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import ArtForm from '@/components/core/forms/art-form/index.vue'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  interface Props {
    visible: boolean
    editData?: AppRouteRecord | any
    type: string
    pid?: number
    appName?: number | string
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: any): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    type: 'add',
    pid: 0,
    appName: 'system'
  })

  const emit = defineEmits<Emits>()

  const form = reactive<any>({
    id: 0,
    name: '',
    code: '',
    sort: 50,
    pid: 0,
    status: 1,
    app_name: 'system'
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: configApi,
      showValidationError: true,
      resetFields: () => {
        form.status = 1
        form.sort = 50
        form.pid = 0
      },
      beforeWatchLoad: () => {
        form.id = 0
        form.pid = props.pid
        form.app_name = props.appName
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入配置名称', trigger: 'blur' },
      { min: 1, max: 20, message: '长度在 1 到 20 个字符', trigger: 'blur' }
    ],
    code: [{ required: true, message: '输入配置标识', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '配置名称', key: 'name', type: 'input', props: { placeholder: '请输入配置名称' } },
      { label: '配置标识', key: 'code', type: 'input', props: { placeholder: '如：Config' } },
      {
        label: '状态',
        key: 'status',
        type: 'dict',
        props: { dict: 'status', type: 'radio', mode: 'border' }
      }
    ]
  })
</script>
