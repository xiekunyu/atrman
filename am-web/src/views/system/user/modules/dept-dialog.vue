<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="30%"
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
      label-width="100px"
      :show-reset="false"
      :show-submit="false"
    >
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
  import { reactive, computed } from 'vue'
  import type { FormRules } from 'element-plus'
  import deptApi from '@/api/system/dept'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  interface UserFormData {
    id: number
    name: string
    sort: number
    pid: number
    status: number
  }

  interface Props {
    visible: boolean
    type: string
    pid: number
    editData?: Partial<Api.SystemManage.UserListItem>
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit', data: UserFormData): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    type: 'add',
    pid: 0
  })

  const emit = defineEmits<Emits>()

  const form = reactive<UserFormData>({
    id: 0,
    name: '',
    sort: 50,
    pid: 0,
    status: 1
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: deptApi,
      resetOnCancel: true,
      resetFields: () => {
        form.id = 0
        form.sort = 0
        form.pid = 0
      },
      beforeWatchLoad: () => {
        form.id = 0
        form.pid = props.pid
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入部门名称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      {
        label: '父部门',
        key: 'pid',
        type: 'remote',
        props: { checkStrictly: true, clearable: true, remote: 'dept_list', type: 'tree' }
      },
      { label: '部门名称', key: 'name', type: 'input', props: { placeholder: '请输入部门名称' } },
      { label: '排序', key: 'sort', type: 'input', props: { placeholder: '请输入排序' } },
      {
        label: '状态',
        key: 'status',
        type: 'dict',
        props: { dict: 'status', type: 'radio', mode: 'border' }
      }
    ]
  })
</script>
