<!-- 站内信组件 -->
<template>
  <div
    class="art-notification-panel art-card-sm !shadow-xl"
    :style="{
      transform: show ? 'scaleY(1)' : 'scaleY(0.9)',
      opacity: show ? 1 : 0
    }"
    v-show="visible"
    @click.stop
  >
    <div class="flex-cb px-3.5 mt-3.5">
      <span class="text-base font-medium text-g-800">站内信</span>
      <span
        class="text-xs text-g-800 px-1.5 py-1 c-p select-none rounded hover:bg-g-200"
        @click="handleMarkAllRead"
      >
        全部标为已读
      </span>
    </div>

    <div class="w-full h-[calc(100%-60px)]">
      <div class="h-[calc(100%-60px)] overflow-y-scroll scrollbar-thin">
        <!-- 站内信列表 -->
        <ul>
          <li
            v-for="(item, index) in inboxList"
            :key="index"
            class="box-border flex-c px-3.5 py-3.5 c-p last:border-b-0 hover:bg-g-200/60"
            @click="handleInboxClick(item)"
          >
            <div
              class="size-9 leading-9 text-center rounded-lg flex-cc"
              :class="[getInboxStyle(item.type).iconClass]"
            >
              <ArtSvgIcon class="text-lg !bg-transparent" :icon="getInboxStyle(item.type).icon" />
            </div>
            <div class="w-[calc(100%-45px)] ml-3.5">
              <h4 class="text-sm font-normal leading-5.5 text-g-900 flex items-center gap-1.5">
                {{ item.title }}
                <span v-if="!item.is_read" class="inline-block size-2 rounded-full bg-danger flex-shrink-0" />
              </h4>
              <p class="mt-1.5 text-xs text-g-500">{{ formatTime(item.create_time) }}</p>
            </div>
          </li>
        </ul>

        <!-- 空状态 -->
        <div
          v-show="inboxList.length === 0"
          class="relative top-25 h-full text-g-500 text-center !bg-transparent"
        >
          <ArtSvgIcon icon="ri:inbox-2-line" class="text-5xl" />
          <p class="mt-3.5 text-xs !bg-transparent">暂无站内信</p>
        </div>
      </div>

      <div class="relative box-border w-full px-3.5">
        <ElButton class="w-full mt-3" @click="handleViewAll" v-ripple>
          {{ $t('notice.viewAll') }}
        </ElButton>
      </div>
    </div>

    <div class="h-10"></div>
  </div>
</template>

<script setup lang="ts">
  import { ref, watch, onMounted } from 'vue'
  import { useRouter } from 'vue-router'
  import notifyApi from '@/api/notify'

  defineOptions({ name: 'ArtNotification' })

  interface InboxItem {
    id: number
    title: string
    content: string
    type: string
    is_read: number
    create_time: number | string
  }

  interface NoticeStyle {
    icon: string
    iconClass: string
  }

  const router = useRouter()

  const props = defineProps<{
    value: boolean
  }>()

  const emit = defineEmits<{
    'update:value': [value: boolean]
  }>()

  const show = ref(false)
  const visible = ref(false)

  // ==================== 数据管理 ====================

  const inboxList = ref<InboxItem[]>([])
  const unreadCount = ref(0)

  /** 加载站内信数据 */
  const loadInbox = async () => {
    try {
      const res = await notifyApi.inbox.list({ page: 1, pageSize: 10 }) as any
      if (res?.items) {
        inboxList.value = res.items
      }
      if (res?.unread_count !== undefined) {
        unreadCount.value = res.unread_count
      }
    } catch {
      // 静默失败
    }
  }

  /** 加载未读数量（轻量接口） */
  const loadUnreadCount = async () => {
    try {
      const res = await notifyApi.inbox.unreadCount() as any
      unreadCount.value = res?.count ?? 0
    } catch {
      // 静默失败
    }
  }

  /** 格式化时间（支持 int 时间戳和 datetime 字符串） */
  const formatTime = (time: number | string): string => {
    if (!time) return ''
    if (typeof time === 'number') {
      const d = new Date(time * 1000)
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
    }
    return String(time)
  }

  // ==================== 样式管理 ====================

  const inboxStyleMap: Record<string, NoticeStyle> = {
    system: { icon: 'ri:notification-3-line', iconClass: 'bg-theme/12 text-theme' },
    activity: { icon: 'ri:gift-line', iconClass: 'bg-warning/12 text-warning' },
    notice: { icon: 'ri:mail-line', iconClass: 'bg-success/12 text-success' }
  }

  const getInboxStyle = (type: string): NoticeStyle => {
    return inboxStyleMap[type] || inboxStyleMap.system
  }

  // ==================== 交互操作 ====================

  /** 点击站内信条目：标记已读 */
  const handleInboxClick = async (item: InboxItem) => {
    if (!item.is_read) {
      try {
        await notifyApi.inbox.markRead({ id: item.id })
        item.is_read = 1
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      } catch {
        // 静默失败
      }
    }
  }

  /** 全部标记已读 */
  const handleMarkAllRead = async () => {
    try {
      await ElMessageBox.confirm('确定将所有站内信标记为已读？', '提示', { type: 'info' })
      await notifyApi.inbox.markAllRead()
      inboxList.value.forEach((item) => (item.is_read = 1))
      unreadCount.value = 0
    } catch {
      // 用户取消或请求失败
    }
  }

  /** 查看全部 */
  const handleViewAll = () => {
    emit('update:value', false)
    router.push('/notify/inbox')
  }

  // ==================== 动画 & 生命周期 ====================

  const showNotice = (open: boolean) => {
    if (open) {
      visible.value = true
      loadInbox()
      setTimeout(() => {
        show.value = true
      }, 5)
    } else {
      show.value = false
      setTimeout(() => {
        visible.value = false
      }, 350)
    }
  }

  onMounted(() => {
    loadUnreadCount()
  })

  watch(
    () => props.value,
    (newValue) => {
      showNotice(newValue)
    }
  )
</script>

<style scoped>
  @reference '@styles/core/tailwind.css';

  .art-notification-panel {
    @apply absolute 
    top-14.5 
    right-5 
    w-90 
    overflow-hidden 
    transition-all 
    duration-300
    origin-top 
    will-change-[top,left] 
    max-[640px]:top-[65px]
    max-[640px]:right-0
    max-[640px]:w-full 
    max-[640px]:h-[80vh];
  }

  .scrollbar-thin::-webkit-scrollbar {
    width: 5px !important;
  }

  .dark .scrollbar-thin::-webkit-scrollbar-track {
    background-color: var(--default-box-color);
  }

  .dark .scrollbar-thin::-webkit-scrollbar-thumb {
    background-color: #222 !important;
  }
</style>
