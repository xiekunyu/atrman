<template>
  <div
    class="plugin-card"
    :class="{ 'is-enabled': plugin.status === 1, 'is-disabled': plugin.status !== 1 }"
    @click.stop="emit('detail')"
    @dblclick.stop="emit('open')"
  >
    <div
      class="plugin-icon"
      :class="{ 'is-image': isImagePluginIcon(plugin, failedIconKeys) }"
      :style="getPluginIconStyle(plugin, failedIconKeys)"
    >
      <img
        v-if="isImagePluginIcon(plugin, failedIconKeys)"
        :src="getPluginIcon(plugin, failedIconKeys)"
        class="plugin-icon-img"
        alt=""
        @error="handleIconError"
      />
      <ArtSvgIcon v-else :icon="getPluginIcon(plugin, failedIconKeys)" />
    </div>
    <div class="plugin-info">
      <div class="plugin-name-row">
        <div class="plugin-name">{{ plugin.title || plugin.name }}</div>
        <ElTag :type="getStatusType(plugin.status)" size="small" effect="plain">
          {{ getStatusText(plugin.status) }}
        </ElTag>
      </div>
      <div class="plugin-desc">{{ description }}</div>
      <div v-if="getRequirementTags(plugin).length || composerBadge" class="plugin-tags">
        <ElTag
          v-for="tag in getRequirementTags(plugin)"
          :key="tag.text"
          :type="tag.type"
          size="small"
          effect="plain"
        >
          {{ tag.text }}
        </ElTag>
        <ElTag
          v-if="composerBadge"
          class="composer-badge"
          :type="composerBadge.type"
          size="small"
          effect="plain"
          @click.stop="emit('action', 'composer')"
        >
          {{ composerBadge.text }}
        </ElTag>
      </div>
    </div>
    <PluginActionMenu
      :plugin="plugin"
      :editable="editable"
      :loading="loading"
      @open="emit('open')"
      @detail="emit('detail')"
      @edit="emit('edit')"
      @action="(action) => emit('action', action)"
      @backup="emit('backup')"
      @uninstall="emit('uninstall')"
      @requirement-invalid="emit('requirementInvalid')"
    />
  </div>
</template>

<script setup lang="ts">
  import {
    getComposerBadge,
    getPluginIcon,
    getPluginIconKey,
    getPluginIconStyle,
    getRequirementTags,
    getStatusText,
    getStatusType,
    isImagePluginIcon,
    type PluginRecord
  } from '@/utils/plugin'
  import PluginActionMenu from './plugin-action-menu.vue'

  interface Props {
    plugin: PluginRecord
    editable?: boolean
    custom?: boolean
    loading?: boolean
  }

  interface Emits {
    (e: 'open'): void
    (e: 'detail'): void
    (e: 'edit'): void
    (e: 'action', action: 'install' | 'enable' | 'disable' | 'reload' | 'composer'): void
    (e: 'backup'): void
    (e: 'uninstall'): void
    (e: 'requirementInvalid'): void
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()
  const failedIconKeys = ref<Set<string>>(new Set())

  const description = computed(() => {
    if (Number(props.plugin.status) !== 1) {
      return props.plugin.description || props.plugin.version || getStatusText(props.plugin.status)
    }
    return props.custom ? '已启用' : props.plugin.description || props.plugin.version || '已启用'
  })

  // 依赖角标：仅当声明了 composer 依赖且检测状态需要处理时展示，点击直接弹安装窗口
  const composerBadge = computed(() => getComposerBadge(props.plugin))

  const handleIconError = () => {
    failedIconKeys.value = new Set([...failedIconKeys.value, getPluginIconKey(props.plugin)])
  }
</script>

<style scoped lang="scss">
  .plugin-card {
    position: relative;
    z-index: 0;
    display: flex;
    align-items: center;
    min-height: 68px;
    padding: 14px 42px 14px 16px;
    overflow: visible;
    cursor: pointer;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    transition:
      border-color 0.2s ease,
      box-shadow 0.2s ease,
      transform 0.2s ease;

    &:hover {
      z-index: 1;
      border-color: var(--el-color-primary-light-5);
      box-shadow: 0 8px 20px rgb(15 23 42 / 8%);
      transform: translateY(-1px);

      :deep(.plugin-more) {
        opacity: 1;
      }
    }

    &.is-disabled {
      color: var(--el-text-color-secondary);

      .plugin-icon {
        opacity: 0.55;
      }
    }
  }

  .plugin-icon {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    margin-right: 14px;
    font-size: 20px;
    color: #fff;
    border-radius: 6px;

    &.is-image {
      overflow: hidden;
      color: inherit;
    }
  }

  .plugin-icon-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .plugin-info {
    min-width: 0;
  }

  .plugin-name-row {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }

  .plugin-name {
    min-width: 0;
    overflow: hidden;
    font-size: 14px;
    font-weight: 600;
    color: var(--art-text-gray-900);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .plugin-desc {
    max-width: 160px;
    margin-top: 4px;
    overflow: hidden;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .plugin-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 6px;
  }

  .composer-badge {
    cursor: pointer;
    transition:
      filter 0.2s ease,
      box-shadow 0.2s ease;

    &:hover {
      filter: brightness(0.94);
      box-shadow: 0 0 0 1px currentcolor inset;
    }
  }
</style>
