<!-- 图标组件：支持 Iconify 图标名，也支持图片地址（如插件 logo） -->
<template>
  <img
    v-if="isImage"
    :src="resolvedSrc"
    class="art-svg-icon art-svg-icon--image inline"
    v-bind="bindAttrs"
  />
  <Icon v-else-if="icon" :icon="icon" v-bind="bindAttrs" class="art-svg-icon inline" />
</template>

<script setup lang="ts">
  import { Icon } from '@iconify/vue'
  import { getApiBaseURL } from '@/utils/env'

  defineOptions({ name: 'ArtSvgIcon', inheritAttrs: false })

  interface Props {
    /** Iconify 图标名，或图片地址（http(s)/data/相对路径，如插件 logo） */
    icon?: string
  }

  const props = defineProps<Props>()

  const attrs = useAttrs()

  const IMAGE_RE = /\.(png|jpe?g|gif|svg|webp|ico|bmp)(\?.*)?$/i

  // 是否按图片渲染：绝对 URL、data URI，或带图片后缀 / 插件资源前缀的相对路径
  const isImage = computed(() => {
    const ic = props.icon || ''
    if (!ic) return false
    return (
      /^(https?:)?\/\//i.test(ic) ||
      ic.startsWith('data:') ||
      IMAGE_RE.test(ic) ||
      ic.startsWith('/app/')
    )
  })

  // 相对路径补后端接口前缀（插件静态资源由 am-serve 提供）：
  // 开发走环境变量代理前缀，生产直接用当前访问域名
  const resolvedSrc = computed(() => {
    const ic = props.icon || ''
    if (/^(https?:)?\/\//i.test(ic) || ic.startsWith('data:')) return ic
    const base = getApiBaseURL()
    return base + ic
  })

  const bindAttrs = computed<{ class: string; style: string }>(() => ({
    class: (attrs.class as string) || '',
    style: (attrs.style as string) || ''
  }))
</script>

<style scoped>
  .art-svg-icon--image {
    /* 与 Iconify <svg> 行为一致：尺寸跟随 font-size（em） */
    width: 1em;
    height: 1em;
    /* 抵消 Tailwind Preflight 对 img 的 max-width:100% / height:auto：
       否则侧栏收起时图片会被更窄的 .menu-icon 容器压缩变小（svg 不受该规则影响） */
    max-width: none;
    flex-shrink: 0;
    object-fit: contain;
    vertical-align: -0.125em;
  }
</style>
