<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="modelValue"
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
      label-width="120px"
      :show-reset="false"
      :show-submit="false"
    >
      <template #header>
        <div class="options-list">
          <div
            v-for="(option, index) in headerOptions || []"
            :key="index"
            class="option-item flex items-center gap-2"
          >
            <ElInput v-model="option.label" placeholder="参数名" class="option-label" />
            <ElInput v-model="option.value" placeholder="参数值" class="option-value" />
            <ElButton
              type="danger"
              :icon="Minus"
              circle
              @click="removeOption(1, index)"
              size="small"
            />
          </div>
          <ElButton type="primary" size="small" class="add-more" @click="addOption(1)">
            添加参数
          </ElButton>
        </div>
      </template>
      <template #params>
        <div class="options-list">
          <div
            v-for="(option, index) in options || []"
            :key="index"
            class="option-item flex items-center gap-2"
          >
            <ElInput v-model="option.label" placeholder="参数名" class="option-label" />
            <ElInput v-model="option.value" placeholder="参数值" class="option-value" />
            <ElButton
              type="danger"
              :icon="Minus"
              circle
              @click="removeOption(2, index)"
              size="small"
            />
          </div>
          <ElButton type="primary" size="small" class="add-more" @click="addOption(2)">
            添加参数
          </ElButton>
        </div>
      </template>
    </AmForm>

    <template #footer>
      <span class="dialog-footer">
        <ElButton @click="handleCancel">取 消</ElButton>
        <ElButton type="primary" @click="handleSubmit">确 定</ElButton>
      </span>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { Minus } from '@element-plus/icons-vue'
  import type { FormRules } from 'element-plus'
  import remoteApi from '@/api/system/remote'
  import { createLabelTooltip } from '@/utils/custom/index'
  import type { FormItem } from '@/components/business/am-form/index.vue'
  import { useRemoteDataStore } from '@/store/modules/remoteData'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  const remoteDataStore = useRemoteDataStore()

  type RemoteItem = Api.SystemManage.RemoteItem

  interface Props {
    modelValue: boolean
    type: string
    showType?: number
    editData?: Partial<RemoteItem>
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', data: RemoteItem): void
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    type: 'add',
    showType: 1,
    editData: undefined
  })

  const emit = defineEmits<Emits>()

  const options = ref<any[]>([])
  const headerOptions = ref<any[]>([])

  const form = reactive<RemoteItem>({
    id: 0,
    name: '',
    code: '',
    api_url: '',
    method: 1,
    type: 1,
    label_field: '',
    value_field: '',
    children_field: '',
    status: 1
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: remoteApi,
      closeEvent: 'update:modelValue',
      submitEvent: 'success',
      loadIsEdit: () => true,
      resetFields: () => {
        form.id = 0
        form.status = 1
        form.sort = 50
      },
      beforeWatchLoad: () => {
        options.value = []
        headerOptions.value = []
      },
      // 将请求头/附加参数 JSON 转换为数组格式回显
      afterLoad: (row) => {
        options.value = row.params
          ? Object.entries(row.params).map((item: any) => ({ label: item[0], value: item[1] }))
          : []
        headerOptions.value = row.header
          ? Object.entries(row.header).map((item: any) => ({ label: item[0], value: item[1] }))
          : []
      },
      buildPayload: () => {
        // 将Options转换为key:value格式
        form.params = Object.fromEntries(
          options.value.reduce((acc, cur) => [...acc, [cur.label, cur.value]], [])
        )
        form.header = Object.fromEntries(
          headerOptions.value.reduce((acc, cur) => [...acc, [cur.label, cur.value]], [])
        )
        return { ...form }
      },
      onSuccess: () => remoteDataStore.refreshCache()
    })

  onMounted(() => {
    form.type = props.showType
  })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入名称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    code: [
      { required: true, message: '请输入编码', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ],
    api_url: [
      { required: true, message: '请输入API路径', trigger: 'blur' },
      { min: 2, max: 128, message: '长度在 2 到 128 个字符', trigger: 'blur' }
    ],
    label_field: [
      { required: true, message: '请输入label字段', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ],
    value_field: [
      { required: true, message: '请输入value字段', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '名称', key: 'name', type: 'input', props: { placeholder: '请输入名称' } },
      { label: '标识', key: 'code', type: 'input', props: { placeholder: '请输入标识' } },
      {
        label: createLabelTooltip('API路径', '可以是Url，也可以是相对路径，格式:/api/remote/list'),
        key: 'api_url',
        type: 'input',
        props: { placeholder: '请输入API路径' }
      },
      {
        label: '请求方法',
        key: 'method',
        type: 'dict',
        props: { dict: 'method_type', type: 'radiogroup', mode: 'border' }
      },
      {
        label: '展示类型',
        key: 'type',
        type: 'dict',
        props: { dict: 'show_type', type: 'radiogroup', mode: 'border' }
      },
      {
        label: createLabelTooltip('label字段', '将数据表的字段作为下拉列表的label字段'),
        key: 'label_field',
        type: 'input',
        props: { placeholder: '请输入label字段' }
      },
      {
        label: createLabelTooltip('value字段', '将数据表的字段作为下拉列表的value字段'),
        key: 'value_field',
        type: 'input',
        props: { placeholder: '请输入value字段' }
      },
      {
        label: createLabelTooltip(
          'children字段',
          '将数据表的字段作为下拉列表的children字段，仅在展示类型为树状结构时生效'
        ),
        key: 'children_field',
        type: 'input',
        props: { placeholder: '请输入children字段' }
      },
      {
        label: createLabelTooltip('请求头', '格式：{"key1":"value1","key2":"value2"}'),
        key: 'header'
      },
      {
        label: createLabelTooltip('附加参数', '格式：{"key1":"value1","key2":"value2"}'),
        key: 'params'
      },
      {
        label: '状态',
        key: 'status',
        type: 'dict',
        props: { dict: 'status', type: 'radiogroup', mode: 'border' }
      }
    ]
  })

  // 添加选项
  const addOption = (type: number) => {
    if (type == 1) {
      if (!headerOptions.value) {
        headerOptions.value = []
      }
      headerOptions.value.push({
        label: `key${headerOptions.value.length + 1}`,
        value: `${headerOptions.value.length + 1}`
      })
    } else {
      if (!options.value) {
        options.value = []
      }
      options.value.push({
        label: `key${options.value.length + 1}`,
        value: `${options.value.length + 1}`
      })
    }
  }

  // 删除选项
  const removeOption = (type: number, index: number) => {
    if (type == 1) {
      if (headerOptions.value && headerOptions.value.length > 0) {
        headerOptions.value.splice(index, 1)
      }
    } else {
      if (options.value && options.value.length > 0) {
        options.value.splice(index, 1)
      }
    }
  }
</script>
<style scoped lang="scss">
  .options-list {
    display: flex;
    flex-wrap: wrap;
    .option-item {
      margin-bottom: 10px;
    }
  }
</style>
