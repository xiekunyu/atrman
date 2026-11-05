<!-- 个人中心页面 -->
<template>
  <div class="w-full h-full p-0 bg-transparent border-none shadow-none">
    <div class="relative flex-b mt-2.5 max-md:block max-md:mt-1">
      <div class="w-112 mr-5 max-md:w-full max-md:mr-0">
        <div class="art-card-sm relative p-9 pb-6 overflow-hidden text-center">
          <img class="absolute top-0 left-0 w-full h-50 object-cover" src="@imgs/user/bg.webp" />
          <img
            class="relative z-10 w-20 h-20 mt-30 mx-auto object-cover border-2 border-white rounded-full"
            :src="avatarUrl"
          />
          <h2 class="mt-5 text-xl font-normal">{{ displayName }}</h2>
          <div class="mt-5">
            <ElAlert :message="profileText" type="info" :closable="false">{{ profileText }}</ElAlert>
          </div>

          <div class="w-75 mx-auto mt-7.5 text-left">
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:mail-line" class="text-g-700" />
              <span class="ml-2 text-sm">{{ userInfo.email || '未设置邮箱' }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:phone-line" class="text-g-700" />
              <span class="ml-2 text-sm">{{ userInfo.mobile || '未设置手机' }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:user-3-line" class="text-g-700" />
              <span class="ml-2 text-sm">账号：{{ userInfo.username || '-' }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:men-line" class="text-g-700" />
              <span class="ml-2 text-sm">性别：{{ genderText }}</span>
            </div>
            <div class="mt-2.5">
              <ArtSvgIcon icon="ri:user-line" class="text-g-700" />
              <span class="ml-2 text-sm">角色：{{ roleList.join('、') || '-' }}</span>
            </div>
            <div class="mt-2.5">
                <ArtSvgIcon icon="ri:time-line" class="text-g-700" />
                <span class="ml-2 text-sm">最后登录时间：{{ lastLoginTimeText }}</span>
              </div>
              <div class="mt-2.5">
                <ArtSvgIcon icon="ri:global-line" class="text-g-700" />
                <span class="ml-2 text-sm">最后登录IP：{{ userInfo.last_login_ip || '-' }}</span>
              </div>
              <div class="mt-2.5">
                <ArtSvgIcon icon="ri:map-pin-line" class="text-g-700" />
                <span class="ml-2 text-sm">登录归属地：{{ userInfo.ip_location || '-' }}</span>
              </div>
          </div>
        </div>
      </div>
      <div class="flex-1 overflow-hidden max-md:w-full max-md:mt-3.5">
        <div class="art-card-sm">
          <h1 class="p-4 text-xl font-normal border-b border-g-300">基本设置</h1>

          <ElForm
            :model="form"
            class="box-border p-5 [&>.el-row_.el-form-item]:w-[calc(50%-10px)] [&>.el-row_.el-input]:w-full [&>.el-row_.el-select]:w-full"
            ref="ruleFormRef"
            :rules="rules"
            label-width="86px"
            label-position="top"
          >
            <ElFormItem label="头像" prop="avatar_id">
              <AmFileForm
                v-model="form.avatar_id"
                :display-data="avatarDisplayData"
                placeholder="请选择头像"
                :disabled="!isEdit"
              />
            </ElFormItem>

            <ElRow>
              <ElFormItem label="账号" prop="username">
                <ElInput v-model="form.username" disabled />
              </ElFormItem>
              <ElFormItem label="性别" prop="gender" class="ml-5">
                <AmDictForm v-model="form.gender" dict="gender" :disabled="!isEdit" />
              </ElFormItem>
            </ElRow>

            <ElRow>
              <ElFormItem label="昵称" prop="nickname">
                <ElInput v-model="form.nickname" :disabled="!isEdit" />
              </ElFormItem>
              <ElFormItem label="邮箱" prop="email" class="ml-5">
                <ElInput v-model="form.email" :disabled="!isEdit" />
              </ElFormItem>
            </ElRow>

            <ElRow>
              <ElFormItem label="手机" prop="mobile">
                <ElInput v-model="form.mobile" :disabled="!isEdit" />
              </ElFormItem>
            </ElRow>

            <ElFormItem label="个性签名" prop="motto" class="h-32">
              <ElInput type="textarea" :rows="4" v-model="form.motto" :disabled="!isEdit" />
            </ElFormItem>

            <div class="flex-c justify-end [&_.el-button]:!w-27.5">
              <ElButton v-if="isEdit" class="w-22.5" @click="cancelEdit">取消</ElButton>
              <ElButton type="primary" class="w-22.5" v-ripple :loading="saving" @click="edit">
                {{ isEdit ? '保存' : '编辑' }}
              </ElButton>
            </div>
          </ElForm>
        </div>

        <div class="art-card-sm my-5">
          <h1 class="p-4 text-xl font-normal border-b border-g-300">更改密码</h1>

          <ElForm :model="pwdForm" class="box-border p-5" label-width="86px" label-position="top">
            <ElFormItem label="当前密码" prop="password">
              <ElInput
                v-model="pwdForm.password"
                type="password"
                :disabled="!isEditPwd"
                show-password
              />
            </ElFormItem>

            <ElFormItem label="新密码" prop="newPassword">
              <ElInput
                v-model="pwdForm.newPassword"
                type="password"
                :disabled="!isEditPwd"
                show-password
              />
            </ElFormItem>

            <ElFormItem label="确认新密码" prop="confirmPassword">
              <ElInput
                v-model="pwdForm.confirmPassword"
                type="password"
                :disabled="!isEditPwd"
                show-password
              />
            </ElFormItem>

            <div class="flex-c justify-end [&_.el-button]:!w-27.5">
              <ElButton type="primary" class="w-22.5" v-ripple @click="editPwd">
                {{ isEditPwd ? '保存' : '编辑' }}
              </ElButton>
            </div>
          </ElForm>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/modules/user'
  import { fetchGetUserInfo } from '@/api/auth'
  import userApi from '@/api/system/user'
  import { getFileUrl } from '@/utils/file'
  import { ElMessage } from 'element-plus'
  import type { FormInstance, FormRules } from 'element-plus'
  import defaultAvatar from '@imgs/user/avatar.webp'
  import AmDictForm from '@/components/business/am-dict/form/index.vue'
  import AmFileForm from '@/components/business/am-form/file/index.vue'

  defineOptions({ name: 'UserCenter' })

  interface UserForm {
    username: string
    nickname: string
    email: string
    mobile: string
    gender: number
    motto: string
    avatar_id: number[]
  }

  const userStore = useUserStore()
  const userInfo = computed(() => userStore.getUserInfo)

  const isEdit = ref(false)
  const isEditPwd = ref(false)
  const saving = ref(false)
  const ruleFormRef = ref<FormInstance>()
  const avatarDisplayData = ref<any[]>([])

  const avatarUrl = computed(() => getFileUrl((userInfo.value as any)?.avatar, true) || defaultAvatar)
  const displayName = computed(() => userInfo.value.nickname || userInfo.value.username || '未设置昵称')
  const profileText = computed(() => userInfo.value.motto || '这个人很懒，什么都没有留下')
  const genderText = computed(() => {
    const gender = String(userInfo.value.gender ?? '')
    if (gender === '1') return '男'
    if (gender === '0') return '女'
    return '其他'
  })
  const roleList = computed(() => {
    const roles = userInfo.value.roles || []
    return roles.map((role: any) => role?.name || role?.code || role).filter(Boolean)
  })

  const formatLoginTime = (value?: number | string) => {
    if (!value) return '-'
    if (typeof value === 'string' && Number.isNaN(Number(value))) return value

    const timestamp = Number(value)
    const date = new Date(timestamp < 10_000_000_000 ? timestamp * 1000 : timestamp)
    if (Number.isNaN(date.getTime())) return '-'

    const pad = (num: number) => String(num).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(
      date.getHours()
    )}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
  }

  const lastLoginTimeText = computed(() => formatLoginTime(userInfo.value.last_login_time))

  /**
   * 用户信息表单
   */
  const form = reactive<UserForm>({
    username: '',
    nickname: '',
    email: '',
    mobile: '',
    gender: 0,
    motto: '',
    avatar_id: []
  })

  /**
   * 密码修改表单
   */
  const pwdForm = reactive({
    password: '',
    newPassword: '',
    confirmPassword: ''
  })

  /**
   * 表单验证规则
   */
  const rules = reactive<FormRules<UserForm>>({
    nickname: [
      { required: true, message: '请输入昵称', trigger: 'blur' },
      { min: 2, max: 50, message: '长度在 2 到 50 个字符', trigger: 'blur' }
    ],
    email: [{ required: true, message: '请输入邮箱', trigger: 'blur' }],
    mobile: [{ required: true, message: '请输入手机号码', trigger: 'blur' }],
    gender: [{ required: true, message: '请选择性别', trigger: 'change' }]
  })
  const setFormData = () => {
    const info = userInfo.value as any
    const avatarId = Number(info.avatar_id || info.avatar?.id || 0)
    form.username = info.username || ''
    form.nickname = info.nickname || ''
    form.email = info.email || ''
    form.mobile = info.mobile || ''
    form.gender = Number(info.gender ?? '')
    form.motto = info.motto || ''
    form.avatar_id = avatarId ? [avatarId] : []
    avatarDisplayData.value = info.avatar ? [info.avatar] : []
  }

  watch(userInfo, setFormData, { immediate: true, deep: true })

  /**
   * 切换用户信息编辑状态
   */
  const edit = async () => {
    if (!isEdit.value) {
      isEdit.value = true
      return
    }

    await ruleFormRef.value?.validate()
    const currentUser = userInfo.value
    if (!currentUser.id) {
      ElMessage.error('用户信息不存在')
      return
    }

    saving.value = true
    try {
      const payload = {
        id: currentUser.id,
        nickname: form.nickname,
        email: form.email,
        mobile: form.mobile,
        gender: form.gender,
        motto: form.motto,
        avatar_id: form.avatar_id
      }
      await userApi.update(payload)
      const latestUserInfo = await fetchGetUserInfo()
      userStore.setUserInfo(latestUserInfo)
      isEdit.value = false
    } finally {
      saving.value = false
    }
  }

  const cancelEdit = () => {
    setFormData()
    isEdit.value = false
  }

  /**
   * 切换密码编辑状态
   */
  const editPwd = () => {
    isEditPwd.value = !isEditPwd.value
  }
</script>
