<template>
  <AmStructure
    v-model="structureState.visible"
    v-model:display-data="structureState.displayData"
    :type="structureState.type"
    :title="structureState.title"
    :default-value="structureState.defaultValue"
    :show-main-dept="structureState.showMainDept"
    @success="handleStructureSuccess"
    @update:model-value="handleStructureVisibleChange"
  />

  <AmFileSelection
    v-model="fileState.visible"
    :title="fileState.title"
    :multiple="fileState.multiple"
    :type="fileState.type"
    :accept="fileState.accept"
    :default-value="fileState.defaultValue"
    :display-data="fileState.displayData"
    @confirm="handleFileConfirm"
    @cancel="handleFileCancel"
  />

  <AmFormBuilder
    :key="builderKey"
    :visible="builderState.visible"
    :title="builderState.title"
    :items="builderState.items"
    :show-import="builderState.showImport"
    :show-preview="builderState.showPreview"
    :show-export="builderState.showExport"
    @update:visible="handleBuilderVisibleChange"
    @submit="handleBuilderSubmit"
  />

  <ElDialog
    v-model="formState.visible"
    :title="formState.title"
    width="720px"
    align-center
    class="el-dialog-border"
    @closed="handleFormClosed"
  >
    <AmForm
      v-if="formState.visible"
      v-model="formState.model"
      :items="formItems"
      :rules="formRules"
      :span="24"
      :gutter="20"
      label-width="120px"
      v-bind="formState.formProps"
      @submit="handleFormSubmit"
    />
  </ElDialog>
</template>

<script setup lang="ts">
  import AmFileSelection from '@/components/business/am-file-selection/index.vue'
  import AmForm from '@/components/business/am-form/index.vue'
  import AmFormBuilder from '@/components/business/am-form-builder/index.vue'
  import AmStructure from '@/components/business/am-structure/index.vue'
  import { buildFormConfig } from '@/utils/custom'
  import type { Ref } from 'vue'
  import type {
    FilePickerParams,
    FormBuilderParams,
    FormRenderParams,
    StructurePickerParams
  } from '../types'

  type Pending<T = any> = {
    resolve: (value: T) => void
    reject: (reason?: any) => void
  }

  const createCancelled = () => ({ cancelled: true })

  const structurePending = ref<Pending | null>(null)
  const filePending = ref<Pending | null>(null)
  const builderPending = ref<Pending | null>(null)
  const formPending = ref<Pending | null>(null)
  const formSubmitted = ref(false)
  const builderKey = ref(0)

  const structureState = reactive({
    visible: false,
    type: 7,
    title: '选择组织架构',
    defaultValue: {} as any,
    displayData: [] as any[],
    showMainDept: false
  })

  const fileState = reactive({
    visible: false,
    title: '选择文件',
    multiple: false,
    type: 1,
    accept: '',
    defaultValue: [] as any,
    displayData: [] as any[]
  })

  const builderState = reactive({
    visible: false,
    title: '表单生成器',
    items: [] as any[],
    showImport: false,
    showPreview: true,
    showExport: false
  })

  const formState = reactive({
    visible: false,
    title: '填写表单',
    items: [] as any[],
    model: {} as Record<string, any>,
    useBuilderConfig: false,
    formProps: {} as Record<string, any>
  })

  const formConfig = computed(() => {
    if (!formState.useBuilderConfig) {
      return { items: formState.items, rules: {} }
    }
    return buildFormConfig(formState.items)
  })

  const formItems = computed(() => formConfig.value.items || [])
  const formRules = computed(() => formConfig.value.rules || {})

  const rejectActivePicker = (pending: Ref<Pending | null>) => {
    if (!pending.value) return
    pending.value.resolve(createCancelled())
    pending.value = null
  }

  const openStructure = (params: StructurePickerParams = {}) => {
    rejectActivePicker(structurePending)
    structureState.type = params.type || 7
    structureState.title = params.title || '选择组织架构'
    structureState.defaultValue = params.defaultValue || {}
    structureState.displayData = params.displayData || []
    structureState.showMainDept = Boolean(params.showMainDept)
    structureState.visible = true

    return new Promise((resolve, reject) => {
      structurePending.value = { resolve, reject }
    })
  }

  const handleStructureSuccess = (data: any) => {
    if (!structurePending.value) return
    structurePending.value.resolve({
      ...data,
      displayData: structureState.displayData
    })
    structurePending.value = null
  }

  const handleStructureVisibleChange = (visible: boolean) => {
    if (!visible) rejectActivePicker(structurePending)
  }

  const openFile = (params: FilePickerParams = {}) => {
    rejectActivePicker(filePending)
    fileState.title = params.title || '选择文件'
    fileState.multiple = Boolean(params.multiple)
    fileState.type = params.type || 1
    fileState.accept = params.accept || ''
    fileState.defaultValue = params.defaultValue || []
    fileState.displayData = params.displayData || []
    fileState.visible = true

    return new Promise((resolve, reject) => {
      filePending.value = { resolve, reject }
    })
  }

  const handleFileConfirm = (data: any) => {
    if (!filePending.value) return
    filePending.value.resolve(data)
    filePending.value = null
    fileState.visible = false
  }

  const handleFileCancel = () => {
    fileState.visible = false
    rejectActivePicker(filePending)
  }

  const openFormBuilder = (params: FormBuilderParams = {}) => {
    rejectActivePicker(builderPending)
    builderState.title = params.title || '表单生成器'
    builderState.items = params.items || []
    builderState.showImport = Boolean(params.showImport)
    builderState.showPreview = params.showPreview !== false
    builderState.showExport = Boolean(params.showExport)
    builderKey.value += 1
    builderState.visible = true

    return new Promise((resolve, reject) => {
      builderPending.value = { resolve, reject }
    })
  }

  const handleBuilderVisibleChange = (visible: boolean) => {
    builderState.visible = visible
    if (!visible) {
      window.setTimeout(() => rejectActivePicker(builderPending), 0)
    }
  }

  const handleBuilderSubmit = (items: any[]) => {
    if (!builderPending.value) return
    builderPending.value.resolve({ items })
    builderPending.value = null
    builderState.visible = false
  }

  const openFormRender = (params: FormRenderParams) => {
    rejectActivePicker(formPending)
    formSubmitted.value = false
    formState.title = params.title || '填写表单'
    formState.items = params.items || []
    formState.model = { ...(params.model || {}) }
    formState.useBuilderConfig = Boolean(params.useBuilderConfig)
    formState.formProps = params.formProps || {}
    formState.visible = true

    return new Promise((resolve, reject) => {
      formPending.value = { resolve, reject }
    })
  }

  const handleFormSubmit = (data: Record<string, any>) => {
    if (!formPending.value) return
    formSubmitted.value = true
    formPending.value.resolve(data)
    formPending.value = null
    formState.visible = false
  }

  const handleFormClosed = () => {
    if (!formSubmitted.value) rejectActivePicker(formPending)
    formSubmitted.value = false
  }

  defineExpose({
    openStructure,
    openFile,
    openFormBuilder,
    openFormRender
  })
</script>
