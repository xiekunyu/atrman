<template>
  <ElDialog
    :title="dialogTitle"
    :model-value="visible"
    @update:model-value="handleCancel"
    width="860px"
    align-center
    @closed="handleClosed"
  >
    <AmForm
      ref="formRef"
      v-model="formModel"
      :items="formItems"
      :rules="rules"
      :span="width > 640 ? 12 : 24"
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
  import userApi from '@/api/system/user'
  import type { FormItem } from '@/components/core/forms/art-form/index.vue'
  import { useWindowSize } from '@vueuse/core'
  import { useCrudDialog } from '@/hooks/business/useCrudDialog'

  const { width } = useWindowSize()

  interface UserFormData {
    id: number
    username: string
    password: string
    nickname: string
    mobile: string
    email: string
    gender: number
    motto: string
    status: number
    dept_id: number | string
    role_ids: number[]
    avatar_id: number[]
  }

  interface Props {
    visible: boolean
    type: string
    editData?: Partial<Api.SystemManage.UserListItem>
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

  const avatarInfo = ref<any[]>()

  const form = reactive<UserFormData>({
    id: 0,
    username: '',
    password: '',
    nickname: '',
    mobile: '',
    email: '',
    gender: 0,
    motto: '',
    status: 1,
    dept_id: '',
    role_ids: [],
    avatar_id: []
  })

  const { formRef, formModel, dialogTitle, resetForm, handleSubmit, handleCancel, handleClosed } =
    useCrudDialog({
      form,
      props,
      emit,
      api: userApi,
      closeOnSuccess: false,
      resetOnCancel: true,
      skipBaseLoad: true,
      resetFields: () => {
        form.id = 0
        form.status = 1
        form.gender = 0
        form.role_ids = []
      },
      beforeWatchLoad: () => {
        avatarInfo.value = []
      },
      afterLoad: async (row) => {
        avatarInfo.value = []
        // 读取用户信息
        const userInfo = await userApi.read(row.id)
        avatarInfo.value.push(userInfo?.avatar || {})
        if (userInfo) {
          for (const key in form) {
            if (userInfo[key] != null && userInfo[key] != undefined) {
              ;(form as any)[key] = userInfo[key]
            }
          }
        }
        form.avatar_id = [userInfo.avatar_id]
      },
      onSuccess: () => resetForm()
    })

  // 表单验证规则
  const rules = reactive<FormRules>({
    username: [
      { required: true, message: '请输入用户名', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    password: [
      { required: true, message: '请输入密码', trigger: 'blur' },
      { min: 6, max: 20, message: '长度在 6 到 20 个字符', trigger: 'blur' }
    ],
    nickname: [
      { required: true, message: '请输入昵称', trigger: 'blur' },
      { min: 2, max: 20, message: '长度在 2 到 20 个字符', trigger: 'blur' }
    ],
    mobile: [
      { required: true, message: '请输入手机号', trigger: 'blur' },
      { pattern: /^1[3-9]\d{9}$/, message: '请输入正确的手机号格式', trigger: 'blur' }
    ],
    gender: [{ required: true, message: '请选择性别', trigger: 'blur' }],
    dept_id: [{ required: true, message: '请选择部门', trigger: 'blur' }],
    role_ids: [{ required: true, message: '请选择角色', trigger: 'blur' }]
  })

  const formItems = computed<FormItem[]>(() => {
    return [
      { label: '用户名', key: 'username', type: 'input', props: { placeholder: '请输入用户名' } },
      {
        label: '头像',
        key: 'avatar_id',
        type: 'image',
        props: { placeholder: '请上传头像', displayData: avatarInfo.value }
      },
      {
        label: '密码',
        key: 'password',
        type: 'input',
        props: { placeholder: '请输入密码', type: 'password', showPassword: true },
        hidden: props.type === 'edit'
      },
      { label: '昵称', key: 'nickname', type: 'input', props: { placeholder: '请输入昵称' } },
      { label: '手机号', key: 'mobile', type: 'input', props: { placeholder: '请输入手机号' } },
      { label: '邮箱', key: 'email', type: 'input', props: { placeholder: '请输入邮箱' } },
      {
        label: '性别',
        key: 'gender',
        type: 'dict',
        props: { dict: 'gender', type: 'radio', mode: 'border' }
      },
      {
        label: '角色',
        key: 'role_ids',
        type: 'remote',
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
        label: '部门',
        key: 'dept_id',
        type: 'remote',
        props: {
          checkStrictly: true,
          clearable: true,
          remote: 'dept_list',
          type: 'tree',
          placeholder: '请选择部门'
        }
      },
      {
        label: '签名',
        key: 'motto',
        type: 'input',
        props: { placeholder: '请输入签名', type: 'textarea' }
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
