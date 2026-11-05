<template>
  <ElDialog
    :model-value="modelValue"
    title="卸载插件"
    width="520px"
    align-center
    @update:model-value="emit('update:modelValue', $event)"
    @closed="resetForm"
  >
    <div class="uninstall-warning">
      <div class="font-medium">确认卸载「{{ plugin?.title || plugin?.name }}」？</div>
      <div class="mt-2">
        <ElAlert
          class="mt-3"
          type="warning"
          show-icon
          :closable="false"
          :title="`卸载前系统将自动备份插件数据库信息和全部插件文件，随后彻底删除插件文件及数据库信息。请输入插件标识确认：${plugin?.name}`"
        />
      </div>
    </div>
    <ElInput
      v-model="form.confirm_name"
      class="mt-4"
      :placeholder="`请输入 ${plugin?.name || '插件标识'} 确认卸载`"
    />
    <template #footer>
      <ElButton @click="emit('update:modelValue', false)">取消</ElButton>
      <ElButton
        type="danger"
        :loading="loading"
        :disabled="form.confirm_name !== plugin?.name"
        @click="confirmUninstall"
      >
        确认卸载
      </ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import pluginApi from '@/api/plugin/app'
  import type { PluginRecord } from '@/utils/plugin'

  interface Props {
    modelValue: boolean
    plugin?: PluginRecord
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success', plugin: PluginRecord): void
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()
  const loading = ref(false)
  const form = reactive({
    confirm_name: ''
  })

  const resetForm = () => {
    form.confirm_name = ''
  }

  const confirmUninstall = async () => {
    if (!props.plugin) return
    if (form.confirm_name !== props.plugin.name) {
      ElMessage.warning('请输入正确的插件标识后再卸载')
      return
    }

    loading.value = true
    try {
      await pluginApi.uninstall({
        id: props.plugin.id,
        name: props.plugin.name,
        confirm_name: form.confirm_name
      })
      emit('update:modelValue', false)
      emit('success', props.plugin)
    } finally {
      loading.value = false
    }
  }
</script>
