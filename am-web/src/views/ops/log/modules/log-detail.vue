<template>
  <ElDrawer
    :model-value="visible"
    title="操作日志详情"
    size="560px"
    @update:model-value="handleCancel"
  >
    <ElDescriptions :column="1" border>
      <ElDescriptionsItem label="日志ID">{{ data.id }}</ElDescriptionsItem>
      <ElDescriptionsItem label="业务名称">{{ data.business_name || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="操作用户">{{ data.username || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="所属应用">{{ data.app || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="请求方式">
        <ElTag :type="methodTagType">{{ data.method || '-' }}</ElTag>
      </ElDescriptionsItem>
      <ElDescriptionsItem label="请求路由">{{ data.router || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="请求IP">{{ data.ip || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="IP归属地">{{ data.ip_location || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="备注">{{ data.remark || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="操作人">{{ data.user?.nickname || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="操作时间">{{ data.create_time || '-' }}</ElDescriptionsItem>
    </ElDescriptions>

    <div class="mt-4">
      <div class="mb-2 font-semibold">请求参数</div>
      <ElInput
        :model-value="requestData"
        type="textarea"
        :autosize="{ minRows: 8, maxRows: 18 }"
        readonly
      />
    </div>

    <template #footer>
      <ElButton @click="handleCancel">关闭</ElButton>
    </template>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed } from 'vue'

  interface Props {
    visible: boolean
    data?: Record<string, any>
  }

  interface Emits {
    (e: 'update:visible', value: boolean): void
  }

  const props = withDefaults(defineProps<Props>(), {
    visible: false,
    data: () => ({})
  })

  const emit = defineEmits<Emits>()

  const methodTagType = computed(() => {
    const method = props.data?.method
    if (method === 'POST') return 'success'
    if (method === 'PUT') return 'warning'
    if (method === 'DELETE') return 'danger'
    return 'info'
  })

  const requestData = computed(() => {
    const value = props.data?.request_data
    if (!value) return '-'

    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  })

  const handleCancel = () => {
    emit('update:visible', false)
  }
</script>
