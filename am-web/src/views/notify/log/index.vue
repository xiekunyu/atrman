<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="flex justify-between items-center">
          <b>发送记录</b>
          <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshData" />
        </div>
      </template>

      <ArtTable :data="data" :columns="columns" :loading="loading">
        <template #channels="{ row }">
          <div class="flex gap-1 flex-wrap">
            <ElTag
              v-for="(info, type) in row.channels"
              :key="type"
              :type="channelStatusTag(info?.status)"
              size="small"
            >
              {{ channelLabelMap[String(type)] || type }}
            </ElTag>
          </div>
        </template>
        <template #status="{ row }">
          <ElTag :type="statusTagMap[row.status]" size="small">{{
            statusLabelMap[row.status]
          }}</ElTag>
        </template>
        <template #operation="{ row }">
          <ElButton size="small" text type="info" @click="handleDetail(row)">详情</ElButton>
        </template>
      </ArtTable>
    </ElCard>

    <!-- 详情弹窗 -->
    <ElDialog v-model="detailVisible" title="发送记录详情" width="650px" align-center>
      <ElDescriptions :column="2" border v-if="detailData">
        <ElDescriptionsItem label="模板编码">{{ detailData.template_code }}</ElDescriptionsItem>
        <ElDescriptionsItem label="整体状态">
          <ElTag :type="statusTagMap[detailData.status]" size="small">{{
            statusLabelMap[detailData.status]
          }}</ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="标题" :span="2">{{
          detailData.title || '-'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="接收方" :span="2">{{ detailData.receiver }}</ElDescriptionsItem>
        <ElDescriptionsItem label="重试次数">{{ detailData.retry_count || 0 }}</ElDescriptionsItem>
        <ElDescriptionsItem label="创建时间">{{ detailData.create_time }}</ElDescriptionsItem>
      </ElDescriptions>

      <!-- 各通道发送结果 -->
      <div class="mt-4" v-if="detailData.channels">
        <b class="mb-2 block">各通道发送结果</b>
        <ElTable :data="channelsArray" border size="small">
          <ElTableColumn prop="type" label="通道" width="100">
            <template #default="{ row }">{{ channelLabelMap[row.type] || row.type }}</template>
          </ElTableColumn>
          <ElTableColumn prop="status" label="状态" width="100">
            <template #default="{ row }">
              <ElTag :type="channelStatusTag(row.status)" size="small">{{
                channelStatusLabel(row.status)
              }}</ElTag>
            </template>
          </ElTableColumn>
          <ElTableColumn prop="cost_time" label="耗时" width="100">
            <template #default="{ row }">{{ row.cost_time }}ms</template>
          </ElTableColumn>
          <ElTableColumn prop="message" label="结果说明" show-overflow-tooltip />
          <ElTableColumn label="操作" width="80">
            <template #default="{ row }">
              <ElButton
                v-if="row.status === 2"
                size="small"
                text
                type="primary"
                v-auth="'notify:log:retryChannel'"
                @click="handleRetryChannel(row)"
                >重发</ElButton
              >
            </template>
          </ElTableColumn>
        </ElTable>
      </div>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import notifyApi from '@/api/notify'

  defineOptions({ name: 'NotifyLog' })

  const channelLabelMap: Record<string, string> = { email: '邮件', sms: '短信', inbox: '站内信' }
  const statusTagMap: Record<number, 'info' | 'success' | 'danger'> = {
    0: 'info',
    1: 'success',
    2: 'danger'
  }
  const statusLabelMap: Record<number, string> = { 0: '待发送', 1: '成功', 2: '失败' }

  /** 通道状态标签颜色：0=待发送(info) 1=成功(success) 2=失败(danger) 3=跳过(warning) */
  const channelStatusTag = (status?: number): 'info' | 'success' | 'danger' | 'warning' => {
    const map: Record<number, 'info' | 'success' | 'danger' | 'warning'> = {
      0: 'info',
      1: 'success',
      2: 'danger',
      3: 'warning'
    }
    return map[status ?? 0] || 'info'
  }

  const channelStatusLabel = (status?: number) => {
    const map: Record<number, string> = { 0: '待发送', 1: '成功', 2: '失败', 3: '已跳过' }
    return map[status ?? 0] || '未知'
  }

  const detailVisible = ref(false)
  const detailData = ref<any>(null)

  /** 将 channels JSON 对象转为数组供表格渲染 */
  const channelsArray = computed(() => {
    if (!detailData.value?.channels) return []
    return Object.entries(detailData.value.channels).map(([type, info]: [string, any]) => ({
      type,
      status: info?.status ?? 0,
      message: info?.message ?? '',
      cost_time: info?.cost_time ?? 0
    }))
  })

  const { columns, data, loading, refreshData } = useTable({
    core: {
      apiFn: notifyApi.log.list,
      columnsFactory: () => [
        { prop: 'template_code', label: '模板编码', width: 140 },
        { prop: 'channels', label: '通道', width: 200, useSlot: true },
        { prop: 'receiver', label: '接收方', minWidth: 160 },
        { prop: 'title', label: '标题', minWidth: 120 },
        { prop: 'status', label: '状态', width: 80, useSlot: true },
        { prop: 'create_time', label: '时间', width: 160 },
        { prop: 'operation', label: '操作', width: 80, useSlot: true }
      ]
    }
  })

  const handleDetail = (row: any) => {
    detailData.value = row
    detailVisible.value = true
  }

  const handleRetryChannel = async (channelRow: any) => {
    // 从接收方字段提取对应通道的接收方（简单场景：整体 receiver 字段）
    const receiver = prompt(
      `请输入${channelLabelMap[channelRow.type] || channelRow.type}的接收方地址`
    )
    if (!receiver) return

    await notifyApi.log.retryChannel({
      id: detailData.value.id,
      channel: channelRow.type,
      receiver
    })
    refreshData()
  }
</script>
