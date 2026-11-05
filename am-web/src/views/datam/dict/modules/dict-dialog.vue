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
        <AmFormColorPicker v-model="form.color" />
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
  import dictApi from '@/api/system/dict'
  import type { AppRouteRecord } from '@/types/router'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import ArtForm from '@/components/core/forms/art-form/index.vue'
  import AmFormColorPicker from '@/components/business/am-form/colorPicker/index.vue'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  interface DictFormData {
    id: number
    name: string
    code: string
    value: string
    color: string
    sort: number
    pid: number
    status: number
  }

  interface Props {
    visible: boolean
    editData?: AppRouteRecord | any
    type: string
    lockType?: boolean
    pid?: number
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: DictFormData): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    type: 'add',
    lockType: false,
    pid: 0
  })

  const emit = defineEmits<Emits>()

  const form = reactive<DictFormData>({
    id: 0,
    name: '',
    code: '',
    value: '',
    color: '#5d87ff',
    sort: 50,
    pid: 0,
    status: 1
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: dictApi,
      showValidationError: true,
      titleText: (e) => {
        const type = props.pid == 0 ? '字典' : '标签'
        return e ? `编辑${type}` : `新建${type}`
      },
      resetFields: () => {
        form.status = 1
        form.color = '#5d87ff'
        form.sort = 50
        form.pid = 0
      },
      beforeWatchLoad: () => {
        form.id = 0
        form.pid = props.pid
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入字典名称', trigger: 'blur' },
      { min: 1, max: 20, message: '长度在 1 到 20 个字符', trigger: 'blur' }
    ],
    code: [{ required: true, message: '输入字典标识', trigger: 'blur' }],
    value: [{ required: true, message: '请输入字典值', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => {
    const nameLabel = props.pid == 0 ? '字典名称' : '标签名称'
    return [
      { label: nameLabel, key: 'name', type: 'input', props: { placeholder: nameLabel } },
      {
        label: '字典标识',
        key: 'code',
        type: 'input',
        props: { placeholder: '如：User' },
        hidden: form.pid > 0
      },
      {
        label: '字典值',
        key: 'value',
        type: 'input',
        props: { min: 1, controlsPosition: 'right', style: { width: '100%' } },
        hidden: form.pid == 0
      },
      { label: '颜色', key: 'color', type: 'input', hidden: form.pid == 0 },
      {
        label: '排序',
        key: 'sort',
        type: 'number',
        props: { min: 1, controlsPosition: 'right', style: { width: '100%' } },
        hidden: form.pid == 0
      },
      {
        label: '状态',
        key: 'status',
        type: 'dict',
        props: { dict: 'status', type: 'radio', mode: 'border' }
      }
    ]
  })
</script>
