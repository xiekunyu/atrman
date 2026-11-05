<template>
  <el-popover placement="bottom-start" :width="width" trigger="click" v-model:visible="visible">
    <template #reference>
        <el-input-tag
          v-bind="$attrs"
          :model-value="displayValue"
          clearable
          :placeholder="placeholder"
          :disabled="disabled"
          @remove-tag="handleRemove"
          @clear="handleClear"
        />
    </template>

    <div class="user-picker">
      <div class="mb-3">
        <el-input
          v-model="searchText"
          placeholder="搜索用户（用户名/昵称）"
          clearable
          prefix-icon="Search"
          @input="handleSearch"
        />
      </div>

      <div class="h-[300px] overflow-y-auto custom-scrollbar" v-infinite-scroll="handleLoadMore" :infinite-scroll-distance="50" :infinite-scroll-disabled="userStore.loading || userStore.noMore">
        <div v-if="userStore.userList.length === 0" class="text-center text-gray-400 py-8">
          未找到相关用户
        </div>
        <div v-else class="user-list">
          <div
            v-for="user in userStore.userList"
            :key="user.id"
            class="user-item flex items-center gap-2 p-2 rounded cursor-pointer hover:bg-gray-100 transition-colors"
            :class="{ 'bg-primary-50': isSelected(user.id) }"
            @click="handleSelect(user)"
          >
            <el-checkbox
              v-if="multiple"
              :model-value="isSelected(user.id)"
              @click.stop
              @change="() => handleSelect(user)"
            />
            <el-radio
              v-else
              :model-value="modelValue"
              :label="user.id"
              @click.stop
              @change="() => handleSelect(user)"
            >{{ '' }}</el-radio>
            
            <el-avatar :size="28" :src="user.avatar" class="flex-shrink-0">
              <el-icon><User /></el-icon>
            </el-avatar>
            
            <div class="flex-1 min-w-0">
              <div class="font-medium text-gray-900 truncate">
                {{ user.nickname}}
              </div>
              <div class="text-xs text-gray-500">{{ user.username }}</div>
            </div>

            <div class="flex-shrink-0" v-if="isSelected(user.id)">
              <el-icon class="text-primary"><Check /></el-icon>
            </div>
          </div>
        </div>
        <!-- 加载更多 -->
        <div class="text-center text-gray-400 py-4" v-if="userStore.loading">加载中...</div>
        <div class="text-center text-gray-400 py-4" v-if="userStore.noMore && userStore.userList.length > 0">没有更多了</div>
      </div>
    </div>
  </el-popover>
</template>

<script lang="ts" setup>
import { ref, computed, watch, onMounted } from 'vue'
import { User, Check } from '@element-plus/icons-vue'
import { useUserSelectStore } from '@/store/modules/userSelect'
const userStore = useUserSelectStore()
defineOptions({ name: 'AmUserSelect', inheritAttrs: false })

interface UserItem {
  id: number
  username: string
  nickname?: string
  avatar?: string
  [key: string]: any
}

interface Props {
  disabled?: boolean
  placeholder?: string
  multiple?: boolean
  width?: number
  pageSize?: number
  selectedUser?: any
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
  placeholder: '点击选择用户',
  multiple: false,
  width: 300,
  pageSize: 20,
  selectedUser: []
})

// 使用统一的 modelValue 来处理单选和多选
const modelValue = defineModel<number | number[] | any>()

const visible = ref(false)
const searchText = ref('')
const selectedUsers = ref<any[]>([])

// 当前值（统一处理单选和多选）
const currentValue = computed(() => {
  if (props.multiple) {
    return Array.isArray(modelValue.value) ? modelValue.value : []
  }
  return modelValue.value ? [modelValue.value] : []
})

const displayValue = computed(() => {
  return selectedUsers.value.map(u => u.nickname || u.username)
})

const handleRemove = (value : string,index: number) => {
  selectedUsers.value.splice(index, 1)
  if (props.multiple) {
    modelValue.value = selectedUsers.value.map(u => u.id)
  } else {
    modelValue.value = ''
  }
}

const fetchUsers = async () => {
  if (userStore.userList.length === 0 || searchText.value=='') {
    userStore.noMore = false
    userStore.keyword = ''
    await userStore.loadUserPage(1)
  }
}

let searchTimer: ReturnType<typeof setTimeout> | null = null
const handleSearch = () => {
  if (searchTimer) {
    clearTimeout(searchTimer)
  }
  searchTimer = setTimeout(() => {
    userStore.searchUser(searchText.value)
  }, 300)
}

const handleLoadMore = () => {
  if (userStore.loading || userStore.noMore) return
  userStore.loadNextPage()
}

const isSelected = (userId: number) => {
  return currentValue.value.includes(userId)
}

const handleSelect = (user: UserItem) => {

  const values = [...currentValue.value]
  const index = values.indexOf(user.id)
  
  if (props.multiple) {
    if (index > -1) {
      // 取消选择
      values.splice(index, 1)
      selectedUsers.value = selectedUsers.value.filter(u => u.id !== user.id)
    } else {
      // 添加选择
      values.push(user.id)
      selectedUsers.value.push(user)
    }
    modelValue.value = [...values]
  } else {
    // 单选模式
    modelValue.value = user.id
    selectedUsers.value = [user]
    visible.value = false
  }
}

const handleClear = () => {
  if (props.multiple) {
    modelValue.value = []
  } else {
    modelValue.value = undefined as any
  }
  selectedUsers.value = []
}

watch(visible, (val) => {
  if (val) {
    fetchUsers()
  }
})

onMounted(() => {
  fetchUsers()
  selectedUsers.value = props.selectedUser
})
</script>

<style lang="scss" scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #e5e7eb;
  border-radius: 3px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background-color: transparent;
}

.user-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.user-item {
  border-radius: 4px;
  :deep(.el-checkbox), :deep(.el-radio) {
    margin-right: 0;
  }
}

.user-item:hover {
  background-color: #f3f4f6;
}

.user-item.bg-primary-50 {
  background-color: var(--el-color-primary-light-9);
}

.user-tag {
  display: flex;
  align-items: center;
}

:deep(.el-radio) {
  margin-right: 0;
}

:deep(.el-pagination) {
  justify-content: flex-end;
}
</style>
