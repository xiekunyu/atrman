<template>
  <ElDialog
    title="用户资料"
    :model-value="visible"
    width="500px"
    align-center
    @update:model-value="handleCancel"
    @closed="handleClosed"
  >
    <ElSkeleton :loading="loading" animated :rows="8">
      <div v-if="userInfo" class="profile-panel">
        <div class="profile-header">
          <ElImage
            class="size-16 rounded-md"
            :src="avatarSrc"
            :preview-src-list="avatarPreview ? [avatarPreview] : []"
            fit="cover"
            preview-teleported
          />
          <div>
            <div class="flex items-center gap-2 text-lg font-semibold">
              {{ userInfo.nickname || userInfo.username }}
              <ArtSvgIcon icon="ri:user-fill" class="text-blue-400" />
            </div>
            <div class="text-sm text-gray-500">账号：{{ userInfo.username }}</div>
          </div>
        </div>

        <div class="profile-section">
          <div class="profile-row">
            <div class="profile-label">邮箱</div>
            <div class="profile-value">{{ userInfo.email || '-' }}</div>
          </div>
          <div class="profile-row">
            <div class="profile-label">手机</div>
            <div class="profile-value">{{ userInfo.mobile || '-' }}</div>
          </div>
          <div class="profile-row">
            <div class="profile-label">性别</div>
            <div class="profile-value"><AmDict dict="gender" :value="genderValue" /></div>
          </div>
          <div class="profile-row">
            <div class="profile-label">状态</div>
            <div class="profile-value"><AmDict dict="status" :value="statusValue" /></div>
          </div>
          <div class="profile-row">
            <div class="profile-label">签名</div>
            <div class="profile-value">{{ userInfo.motto || '未设置' }}</div>
          </div>
          <div class="profile-row">
            <div class="profile-label">创建时间</div>
            <div class="profile-value">{{ userInfo.create_time || '-' }}</div>
          </div>
        </div>

        <div class="profile-section">
          <div class="profile-row profile-row-top">
            <div class="profile-label">部门</div>
            <div class="profile-value">
              <template v-if="editingDept">
                <AmStructureForm
                  v-model="deptForm"
                  v-model:display-data="deptDisplayData"
                  :type="1"
                  placeholder="选择部门"
                  show-main-dept
                />
              </template>
              <template v-else>
                <div v-if="userInfo.depts?.length" class="profile-link-list">
                  <span
                    v-for="(dept, index) in userInfo.depts"
                    :key="dept.id"
                    class="profile-link-item"
                  >
                    {{ dept.name }}<ElTag v-if="dept.is_main" size="small" class="mx-1">主</ElTag
                    >{{ index < userInfo.depts.length - 1 ? '、' : '' }}
                  </span>
                </div>
                <span v-else>未设置</span>
              </template>
              <ElButton
                v-if="!editingDept && userInfo.id !== 1"
                type="info"
                link
                class="ml-2"
                @click="startEditDept"
                >修改</ElButton
              >
            </div>
          </div>
          <div class="profile-row profile-row-top flex items-center">
            <div class="profile-label">角色</div>
            <div class="profile-value">
              <div v-if="userInfo.roles?.length" class="profile-link-list">
                <span
                  v-for="(role, index) in userInfo.roles"
                  :key="role.id"
                  class="profile-link-item"
                >
                  {{ role.name }}{{ index < userInfo.roles.length - 1 ? '、' : '' }}
                </span>
              </div>
              <span v-else>未设置</span>
            </div>
          </div>
          <div class="profile-row">
            <div class="profile-label">用户ID</div>
            <div class="profile-value">{{ userInfo.id }}</div>
          </div>
        </div>
      </div>
    </ElSkeleton>

    <template #footer>
      <ElButton @click="handleCancel">关 闭</ElButton>
      <ElButton v-if="editingDept" @click="cancelEditDept">取消修改</ElButton>
      <ElButton
        v-if="editingDept"
        type="primary"
        :loading="savingDept"
        v-auth="'system:user:setDepts'"
        @click="saveDept"
        >保存部门</ElButton
      >
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import userApi from '@/api/system/user'
  import AmDict from '@/components/business/am-dict/index.vue'
  import AmStructureForm from '@/components/business/am-form/structure/index.vue'
  import { getFileUrl } from '@/utils/file'

  interface DeptItem {
    id: number
    name: string
    is_main?: number
    stype?: string
    key?: string
  }

  interface RoleItem {
    id: number
    name: string
    code?: string
  }

  interface AvatarInfo {
    url?: string
    thumb?: string
    path?: string
  }

  interface UserProfile {
    id: number
    username: string
    nickname?: string
    email?: string
    mobile?: string
    gender?: number | string
    status?: number | string
    motto?: string
    create_time?: string
    avatar?: AvatarInfo
    depts?: DeptItem[]
    roles?: RoleItem[]
  }

  interface DeptForm {
    user_ids: number[]
    dept_ids: number[]
    tags_ids: number[]
    dept_items: Array<{ id: number; is_main: number }>
  }

  interface Props {
    visible: boolean
    userId?: number
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
    (e: 'updated'): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false
  })

  const emit = defineEmits<Emits>()

  const loading = ref(false)
  const savingDept = ref(false)
  const editingDept = ref(false)
  const userInfo = ref<UserProfile>()
  const deptDisplayData = ref<DeptItem[]>([])
  const deptForm = ref<DeptForm>({
    user_ids: [],
    dept_ids: [],
    tags_ids: [],
    dept_items: []
  })

  const avatarSrc = computed(() => getFileUrl(userInfo.value?.avatar, true))

  const avatarPreview = computed(() => getFileUrl(userInfo.value?.avatar, false))

  const genderValue = computed(() => userInfo.value?.gender ?? '')
  const statusValue = computed(() => userInfo.value?.status ?? '')

  const loadUserInfo = async () => {
    if (!props.userId) return
    loading.value = true
    try {
      const data = await userApi.read(props.userId)
      userInfo.value = data as unknown as UserProfile
      resetDeptForm()
    } finally {
      loading.value = false
    }
  }

  const resetDeptForm = () => {
    const depts = userInfo.value?.depts || []
    deptForm.value = {
      user_ids: [],
      dept_ids: depts.map((item: any) => item.id),
      tags_ids: [],
      dept_items: depts.map((item: any) => ({ id: item.id, is_main: item.is_main ? 1 : 0 }))
    }
    deptDisplayData.value = depts.map((item: any) => ({
      id: item.id,
      name: item.name,
      stype: 'dept',
      key: `dept_${item.id}`,
      is_main: item.is_main ? 1 : 0
    }))
  }

  const startEditDept = () => {
    resetDeptForm()
    editingDept.value = true
  }

  const cancelEditDept = () => {
    editingDept.value = false
    resetDeptForm()
  }

  const saveDept = async () => {
    if (!userInfo.value) return
    if (!deptForm.value.dept_ids.length) {
      ElMessage.error('请选择部门')
      return
    }
    savingDept.value = true
    try {
      await userApi.batchSet({
        user_ids: [userInfo.value.id],
        type: 'dept',
        dept_ids: deptForm.value.dept_ids,
        dept_items: deptForm.value.dept_items
      })
      editingDept.value = false
      await loadUserInfo()
      emit('updated')
    } finally {
      savingDept.value = false
    }
  }

  const handleCancel = () => {
    emit('update:visible', false)
  }

  const handleClosed = () => {
    userInfo.value = undefined
    editingDept.value = false
    deptDisplayData.value = []
  }

  watch(
    () => props.visible,
    (visible) => {
      if (visible) {
        loadUserInfo()
      }
    }
  )
</script>

<style scoped lang="scss">
  .profile-panel {
    color: var(--art-gray-900);
  }

  .profile-header {
    display: flex;
    gap: 16px;
    align-items: center;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--art-border-color);
  }

  .profile-section {
    padding: 10px 0;
    border-bottom: 1px solid var(--art-border-color);

    &:last-child {
      border-bottom: 0;
    }
  }

  .profile-row {
    display: flex;
    align-items: center;
    min-height: 36px;
    line-height: 24px;
  }

  .profile-label {
    flex: 0 0 80px;
    color: var(--art-gray-600);
  }

  .profile-value {
    display: flex;
    align-items: center;
    min-width: 0;
    color: var(--art-gray-900);
  }

  .profile-link-list {
    flex: 1;
    line-height: 28px;
  }

  .profile-link-item {
    color: var(--el-color-primary);
  }
</style>
