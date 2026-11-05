<template>
  <ElDropdown trigger="click" teleported class="plugin-dropdown">
    <span class="plugin-action" @click.stop>
      <ArtIconButton v-loading="!!loading" icon="ri:more-2-fill" class="plugin-more" />
    </span>
    <template #dropdown>
      <ElDropdownMenu>
        <ElDropdownItem v-if="plugin.status === 1 && hasPluginEntry(plugin)" @click="emit('open')">
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:external-link-line" />
            <span>打开</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem @click="emit('detail')">
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:file-list-3-line" />
            <span>详情</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem v-if="editable" @click="emit('edit')">
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:edit-line" />
            <span>编辑</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="plugin.status === 2 && hasAuth('system:plugin:install')"
          :disabled="loading"
          @click="emit('action', 'install')"
        >
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:download-line" />
            <span>安装</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="plugin.status === 0 && hasAuth('system:plugin:enable')"
          :disabled="loading || !canEnablePlugin(plugin)"
          @click="handleEnable"
        >
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:play-circle-line" />
            <span>启用</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="plugin.status === 1 && hasAuth('system:plugin:disable')"
          :disabled="loading"
          @click="emit('action', 'disable')"
        >
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:pause-circle-line" />
            <span>禁用</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="hasAuth('system:plugin:reload')"
          :disabled="loading"
          @click="emit('action', 'reload')"
        >
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:refresh-line" />
            <span>重载</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="hasAuth('system:plugin:backup')"
          :disabled="loading"
          @click="emit('backup')"
        >
          <div class="flex-c gap-2">
            <ArtSvgIcon icon="ri:archive-line" />
            <span>备份</span>
          </div>
        </ElDropdownItem>
        <ElDropdownItem
          v-if="(plugin.status === 0 || plugin.status === 2) && hasAuth('system:plugin:uninstall')"
          :disabled="loading"
          @click="emit('uninstall')"
        >
          <div class="flex-c gap-2 text-danger">
            <ArtSvgIcon icon="ri:logout-box-r-line" />
            <span>卸载</span>
          </div>
        </ElDropdownItem>
      </ElDropdownMenu>
    </template>
  </ElDropdown>
</template>

<script setup lang="ts">
  import { canEnablePlugin, hasPluginEntry, type PluginRecord } from '@/utils/plugin'
  import { useAuth } from '@/hooks'

  const { hasAuth } = useAuth()

  interface Props {
    plugin: PluginRecord
    editable?: boolean
    loading?: boolean
  }

  interface Emits {
    (e: 'open'): void
    (e: 'detail'): void
    (e: 'edit'): void
    (e: 'action', action: 'install' | 'enable' | 'disable' | 'reload'): void
    (e: 'backup'): void
    (e: 'uninstall'): void
    (e: 'requirementInvalid'): void
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()

  const handleEnable = () => {
    if (props.loading) return
    if (!canEnablePlugin(props.plugin)) {
      emit('requirementInvalid')
      return
    }
    emit('action', 'enable')
  }
</script>

<style scoped lang="scss">
  .plugin-dropdown {
    position: absolute;
    top: 4px;
    right: 4px;
    z-index: 2;
    display: inline-flex;
  }

  .plugin-action {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 2;
    display: inline-flex;
  }

  .plugin-more {
    opacity: 0;
    transition: opacity 0.2s ease;
  }
</style>
