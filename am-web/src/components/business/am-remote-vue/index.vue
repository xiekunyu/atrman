<template>
  <div class="am-remote-vue mb-5">
    <slot v-if="!remoteSource" name="empty">
      <ElEmpty :description="emptyText" :image-size="80" />
    </slot>

    <div v-else-if="loading" class="am-remote-vue__state" v-loading="true">
      <span>{{ loadingText }}</span>
    </div>

    <slot v-else-if="error" name="error" :error="error" :reload="handleRetry">
      <ElAlert
        :title="errorText"
        :description="error.message"
        type="error"
        show-icon
        :closable="false"
      />
      <ElButton class="am-remote-vue__retry" type="primary" size="small" @click="handleRetry">
        重试
      </ElButton>
    </slot>

    <Suspense v-else-if="component">
      <component
        :is="component"
        v-bind="mergedComponentProps"
        @message="(payload: any) => emit('message', payload)"
      />
      <template #fallback>
        <div class="am-remote-vue__state" v-loading="true">
          <span>{{ loadingText }}</span>
        </div>
      </template>
    </Suspense>
  </div>
</template>

<script setup lang="ts">
  import type { Component } from 'vue'
  import { useRemoteVue } from '@/hooks/remote-vue/useRemoteVue'
  import type { RemoteVueComponentMeta } from '@/types/remote-vue'

  defineOptions({ name: 'AmRemoteVue' })

  interface Props {
    meta?: RemoteVueComponentMeta
    url?: string
    name?: string
    componentProps?: Record<string, any>
    loadingText?: string
    emptyText?: string
    errorText?: string
    force?: boolean
  }

  const props = withDefaults(defineProps<Props>(), {
    name: 'RemoteVue',
    componentProps: () => ({}),
    loadingText: '远程组件加载中...',
    emptyText: '暂无远程组件',
    errorText: '远程组件加载失败',
    force: false
  })

  const emit = defineEmits<{
    (e: 'loaded', component: Component): void
    (e: 'error', error: Error): void
    (e: 'retry'): void
    (e: 'message', payload: any): void
  }>()

  const remoteSource = computed<RemoteVueComponentMeta | null>(() => {
    if (props.meta) return props.meta
    if (!props.url) return null

    return {
      name: props.name,
      url: props.url
    }
  })

  const mergedComponentProps = computed(() => ({
    ...(remoteSource.value?.props || {}),
    ...props.componentProps,
    componentMeta: remoteSource.value
  }))

  const { component, loading, error, reload } = useRemoteVue(remoteSource, {
    name: props.name,
    force: props.force
  })

  const handleRetry = () => {
    emit('retry')
    reload()
  }

  watch(component, (value) => {
    if (value) emit('loaded', value)
  })

  watch(error, (value) => {
    if (value) emit('error', value)
  })
</script>

<style scoped lang="scss">
  .am-remote-vue {
    width: 100%;
    min-width: 0;
  }

  .am-remote-vue__state {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    color: var(--el-text-color-secondary);
  }

  .am-remote-vue__retry {
    margin-top: 12px;
  }
</style>
