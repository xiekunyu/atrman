<template>
  <ElDrawer
    :model-value="visible"
    title="登录日志详情"
    size="520px"
    @update:model-value="handleCancel"
  >
    <ElDescriptions :column="1" border>
      <ElDescriptionsItem label="日志ID">{{ data.id }}</ElDescriptionsItem>
      <ElDescriptionsItem label="用户名">{{ data.username || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="登录IP">{{ data.ip || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="IP归属地">{{ data.location || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="操作系统">{{ data.os || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="浏览器">{{ data.browser || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="登录状态">
        <ElTag :type="data.status === 1 ? 'success' : 'danger'">
          {{ data.status === 1 ? '成功' : '失败' }}
        </ElTag>
      </ElDescriptionsItem>
      <ElDescriptionsItem label="提示消息">{{ data.message || '-' }}</ElDescriptionsItem>
      <ElDescriptionsItem label="登录时间">{{ data.create_time || '-' }}</ElDescriptionsItem>
    </ElDescriptions>

    <template #footer>
      <ElButton @click="handleCancel">关闭</ElButton>
    </template>
  </ElDrawer>
</template>

<script setup lang="ts">
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

  const handleCancel = () => {
    emit('update:visible', false)
  }
</script>
