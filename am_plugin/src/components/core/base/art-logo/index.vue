<!-- 系统logo -->
<template>
  <div class="flex-cc">
    <img :style="logoStyle" :src="logoSrc" alt="logo" class="w-full h-full" />
  </div>
</template>

<script setup lang="ts">
  import AppConfig from '@/config'
  import defaultLogo from '@imgs/common/logo.webp'
  import { getFileUrl } from '@/utils/file'

  defineOptions({ name: 'ArtLogo' })

  interface Props {
    /** logo 大小 */
    size?: number | string
  }

  const props = withDefaults(defineProps<Props>(), {
    size: 36
  })

  const logoSrc = computed(() => {
    const logo = (AppConfig.systemInfo as any).logo_displayData?.[0]
    return getFileUrl(logo, false) || defaultLogo
  })

  const logoStyle = computed(() => ({ width: `${props.size}px` }))
</script>
