<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="modelValue"
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
      <template #sort>
        <ElInputNumber v-model="form.sort" />
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
  import { reactive, computed } from 'vue'
  import type { FormRules } from 'element-plus'
  import roleApi from '@/api/system/role'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  type RoleListItem = Partial<Api.SystemManage.RoleListItem> & {
    data_scope?: number
    custom_dept_ids?: number[]
  }

  interface Props {
    modelValue: boolean
    type: string
    editData?: RoleListItem
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', data: RoleListItem): void
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    type: 'add',
    editData: undefined
  })

  const emit = defineEmits<Emits>()

  const form = reactive<RoleListItem>({
    id: 0,
    name: '',
    code: '',
    remark: '',
    level: 0,
    status: 1,
    sort: 50,
    data_scope: 2,
    custom_dept_ids: []
  })

  const { formRef, formModel, dialogTitle, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: roleApi,
      closeEvent: 'update:modelValue',
      submitEvent: 'success',
      loadIsEdit: () => true,
      resetFields: () => {
        form.id = 0
        form.status = 1
        form.sort = 50
        form.data_scope = 2
        form.custom_dept_ids = []
      },
      // 列表行不含 custom_dept_ids，编辑态拉详情回显数据权限
      afterLoad: async (row) => {
        if (row.id) {
          const detail = (await roleApi.read(row.id)) as unknown as RoleListItem
          if (detail) {
            form.data_scope = Number(detail.data_scope ?? 2)
            form.custom_dept_ids = (detail.custom_dept_ids ?? []).map(Number)
          }
        }
      }
    })

  const rules = reactive<FormRules>({
    name: [
      { required: true, message: '请输入角色名称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    code: [
      { required: true, message: '请输入角色编码', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '角色名称', key: 'name', type: 'input', props: { placeholder: '请输入角色名称' } },
      { label: '角色编码', key: 'code', type: 'input', props: { placeholder: '请输入角色编码' } },
      {
        label: '角色等级',
        key: 'level',
        type: 'input',
        props: { placeholder: '登记越大权限越高' }
      },
      { label: '排序', key: 'sort' },
      {
        label: '数据权限',
        key: 'data_scope',
        type: 'dict',
        props: { dict: 'sys_data_scope', type: 'select', placeholder: '请选择数据权限' }
      },
      {
        label: '自定义部门',
        key: 'custom_dept_ids',
        type: 'remote',
        hidden: form.data_scope !== 5,
        props: {
          checkStrictly: true,
          clearable: true,
          remote: 'dept_list',
          type: 'tree',
          multiple: true,
          placeholder: '请选择部门'
        }
      },
      {
        label: '描述',
        key: 'remark',
        type: 'input',
        props: { placeholder: '请输入角色描述', type: 'textarea' }
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
