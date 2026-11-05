<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="flex justify-between items-center">
          <b>
            站内信
            <ElBadge v-if="unreadCount > 0" :value="unreadCount" :max="99" class="ml-2" />
          </b>
          <div class="flex flex-wrap am-header-actions">
            <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshData" />
            <ElButton v-auth="'notify:inbox:markAllRead'" @click="handleMarkAllRead"
              >全部已读</ElButton
            >
          </div>
        </div>
      </template>

      <ArtTable :data="data" :columns="columns" :loading="loading">
        <template #title="{ row }">
          <div class="flex items-center gap-2">
            <ElBadge is-dot :hidden="row.is_read === 1" />
            <span :class="{ 'font-bold': row.is_read === 0 }">{{ row.title }}</span>
          </div>
        </template>
        <template #is_read="{ row }">
          <ElTag :type="row.is_read ? 'info' : 'danger'" size="small">
            {{ row.is_read ? '已读' : '未读' }}
          </ElTag>
        </template>
        <template #operation="{ row }">
          <div class="flex">
            <ElButton
              size="small"
              text
              type="primary"
              v-auth="'notify:inbox:read'"
              @click="handleRead(row)"
              >查看</ElButton
            >
            <ElButton
              size="small"
              text
              type="danger"
              v-auth="'notify:inbox:delete'"
              @click="handleDelete(row)"
              >删除</ElButton
            >
          </div>
        </template>
      </ArtTable>
    </ElCard>

    <!-- 消息详情弹窗 -->
    <ElDialog
      v-model="detailVisible"
      :title="detailData?.title || '消息详情'"
      width="600px"
      align-center
    >
      <div v-if="detailData" class="notify-detail">
        <div class="text-gray-400 text-sm mb-3">{{ detailData.create_time }}</div>
        <div class="notify-content" v-html="sanitizedContent"></div>
      </div>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import notifyApi from '@/api/notify'
  import DOMPurify from 'dompurify'

  defineOptions({ name: 'NotifyInbox' })

  const unreadCount = ref(0)
  const detailVisible = ref(false)
  const detailData = ref<any>(null)

  // 站内信内容为富文本 HTML，渲染前统一消毒（与商店详情页同一口径），防止未来不可信变量进入模板后形成存储型 XSS
  const sanitizedContent = computed(() =>
    DOMPurify.sanitize(detailData.value?.content ?? '', { USE_PROFILES: { html: true } })
  )

  const { columns, data, loading, refreshData } = useTable({
    core: {
      apiFn: notifyApi.inbox.list,
      columnsFactory: () => [
        { prop: 'title', label: '标题', useSlot: true },
        { prop: 'type', label: '分类', width: 100 },
        { prop: 'is_read', label: '状态', width: 80, useSlot: true },
        { prop: 'create_time', label: '时间', width: 170 },
        { prop: 'operation', label: '操作', width: 120, useSlot: true }
      ]
    }
  })

  const loadUnreadCount = async () => {
    const res = (await notifyApi.inbox.unreadCount()) as any
    unreadCount.value = res?.count ?? 0
  }

  const handleRead = async (row: any) => {
    const res = (await notifyApi.inbox.read(row.id)) as any
    detailData.value = res
    detailVisible.value = true
    if (row.is_read === 0) {
      row.is_read = 1
      loadUnreadCount()
    }
  }

  const handleDelete = async (row: any) => {
    await ElMessageBox.confirm('确定删除此消息？', '提示', { type: 'warning' })
    await notifyApi.inbox.delete({ id: row.id })
    refreshData()
    loadUnreadCount()
  }

  const handleMarkAllRead = async () => {
    await ElMessageBox.confirm('确定将所有站内信标记为已读？', '提示', { type: 'info' })
    await notifyApi.inbox.markAllRead()
    refreshData()
    loadUnreadCount()
  }

  onMounted(() => loadUnreadCount())
</script>

<style scoped>
  .notify-detail {
    line-height: 1.8;
  }
  .notify-content {
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>
