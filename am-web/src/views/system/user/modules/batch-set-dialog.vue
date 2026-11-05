<template>
  <ElDialog
    title="批量设置成员信息"
    :model-value="visible"
    width="720px"
    align-center
    @update:model-value="handleCancel"
    @closed="handleClosed"
  >
    <AmForm
      ref="formRef"
      v-model="formModel"
      :items="formItems"
      :rules="rules"
      :span="24"
      label-width="110px"
      :show-reset="false"
      :show-submit="false"
    />

    <template #footer>
      <ElButton @click="handleCancel">取 消</ElButton>
      <ElButton type="primary" :loading="loading" @click="handleSubmit">确 定</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, reactive, ref, watch } from 'vue'
  import type { FormRules } from 'element-plus'
  import userApi from '@/api/system/user'
  import type { FormItem } from '@/components/business/am-form/index.vue'

  interface Props {
    visible: boolean
    users?: any[]
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'submit'): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    users: () => []
  })

  const emit = defineEmits<Emits>()

  const formRef = ref()
  const loading = ref(false)
  const userDisplayData = ref<any[]>([])
  const deptDisplayData = ref<any[]>([])

  const form = reactive({
    users: {
      user_ids: [] as number[],
      dept_ids: [] as number[],
      tags_ids: [] as number[],
      dept_items: [] as Array<{ id: number; is_main: number }>
    },
    set_type: 'dept',
    dept: {
      user_ids: [] as number[],
      dept_ids: [] as number[],
      tags_ids: [] as number[],
      dept_items: [] as Array<{ id: number; is_main: number }>
    },
    role_ids: [] as number[],
    status: 1,
    password: ''
  })

  // reactive 常量不能直接作为 v-model（编译出的赋值会抛错），
  // 用可写计算属性做一层合并，保证组件回写时也是原地修改
  const formModel = computed({
    get: () => form,
    set: (val: any) => Object.assign(form, val),
  })

  const rules = reactive<FormRules>({
    users: [
      {
        validator: (_rule, value, callback) => {
          if (!value?.user_ids?.length) {
            callback(new Error('请选择成员'))
            return
          }
          callback()
        },
        trigger: 'change'
      }
    ],
    set_type: [{ required: true, message: '请选择设置类型', trigger: 'change' }],
    dept: [
      {
        validator: (_rule, value, callback) => {
          if (form.set_type === 'dept' && !value?.dept_ids?.length) {
            callback(new Error('请选择部门'))
            return
          }
          callback()
        },
        trigger: 'change'
      }
    ],
    role_ids: [
      {
        validator: (_rule, value, callback) => {
          if (form.set_type === 'role' && !value?.length) {
            callback(new Error('请选择角色'))
            return
          }
          callback()
        },
        trigger: 'change'
      }
    ],
    status: [
      {
        validator: (_rule, value, callback) => {
          if (form.set_type === 'status' && value === '') {
            callback(new Error('请选择状态'))
            return
          }
          callback()
        },
        trigger: 'change'
      }
    ],
    password: [
      {
        validator: (_rule, value, callback) => {
          if (form.set_type !== 'password') {
            callback()
            return
          }
          if (!value) {
            callback(new Error('请输入密码'))
            return
          }
          if (value.length < 6 || value.length > 16) {
            callback(new Error('密码长度为6到16位'))
            return
          }
          callback()
        },
        trigger: 'blur'
      }
    ]
  })

  const formItems = computed<FormItem[]>(() => [
    {
      label: '成员',
      key: 'users',
      type: 'structure',
      props: {
        type: 3,
        placeholder: '选择成员',
        displayData: userDisplayData.value
      }
    },
    {
      label: '设置类型',
      key: 'set_type',
      type: 'radiogroup',
      options: [
        { label: '设置部门', value: 'dept', border: true },
        { label: '设置角色', value: 'role', border: true },
        { label: '设置状态', value: 'status', border: true },
        { label: '设置密码', value: 'password', border: true }
      ]
    },
    {
      label: '部门',
      key: 'dept',
      type: 'structure',
      hidden: form.set_type !== 'dept',
      props: {
        type: 1,
        placeholder: '选择部门',
        displayData: deptDisplayData.value,
        showMainDept: true
      }
    },
    {
      label: '角色',
      key: 'role_ids',
      type: 'remote',
      hidden: form.set_type !== 'role',
      props: {
        checkStrictly: true,
        clearable: true,
        remote: 'role_list',
        type: 'select',
        multiple: true,
        placeholder: '请选择角色'
      }
    },
    {
      label: '状态',
      key: 'status',
      type: 'radiogroup',
      hidden: form.set_type !== 'status',
      options: [
        { label: '启用', value: 1, border: true },
        { label: '禁用', value: 0, border: true }
      ]
    },
    {
      label: '密码',
      key: 'password',
      type: 'input',
      hidden: form.set_type !== 'password',
      props: {
        placeholder: '请输入新密码',
        type: 'password',
        showPassword: true
      }
    }
  ])

  const resetForm = () => {
    form.users = { user_ids: [], dept_ids: [], tags_ids: [], dept_items: [] }
    form.set_type = 'dept'
    form.dept = { user_ids: [], dept_ids: [], tags_ids: [], dept_items: [] }
    form.role_ids = []
    form.status = 1
    form.password = ''
    userDisplayData.value = []
    deptDisplayData.value = []
    formRef.value?.reset()
  }

  const initSelectedUsers = () => {
    const users = props.users || []
    form.users = {
      user_ids: users.map((item: any) => item.id),
      dept_ids: [],
      tags_ids: [],
      dept_items: []
    }
    userDisplayData.value = users.map((item: any) => ({
      id: item.id,
      name: item.nickname || item.username,
      stype: 'user',
      key: `user_${item.id}`
    }))
  }

  const handleSubmit = async () => {
    if (!formRef.value) return
    await formRef.value.validate()
    loading.value = true
    try {
      await userApi.batchSet({
        type: form.set_type,
        user_ids: form.users.user_ids,
        dept_ids: form.dept.dept_ids,
        dept_items: form.dept.dept_items,
        role_ids: form.role_ids,
        status: form.status,
        password: form.password
      })
      emit('submit')
      handleCancel()
    } finally {
      loading.value = false
    }
  }

  const handleCancel = () => {
    emit('update:visible', false)
  }

  const handleClosed = () => {
    resetForm()
  }

  watch(
    () => props.visible,
    (visible) => {
      if (visible) {
        resetForm()
        initSelectedUsers()
      }
    }
  )
</script>
