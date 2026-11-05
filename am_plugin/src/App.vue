<template>
  <ElConfigProvider size="default" :locale="locales[language]" :z-index="3000">
    <RouterView></RouterView>
  </ElConfigProvider>
</template>

<script setup lang="ts">
  import { useUserStore } from './store/modules/user'
  import { useSystemStore } from './store/modules/system'
  import zh from 'element-plus/es/locale/lang/zh-cn'
  import en from 'element-plus/es/locale/lang/en'
  import { toggleTransition } from './utils/ui/animation'
  import { checkStorageCompatibility } from './utils/storage'
  import { initializeTheme } from './hooks/core/useTheme'
  import { hasBridge } from './sdk/bridge'

  const userStore = useUserStore()
  const systemStore = useSystemStore()
  const { language } = storeToRefs(userStore)

  const locales = {
    zh: zh,
    en: en
  }

  onBeforeMount(() => {
    toggleTransition(true)
    // 基座模式下主题由基座统一控制（共用同一 documentElement），
    // 子应用通过 Bridge 同步主题字段即可，不再本地初始化主题、避免互相覆盖。
    if (!hasBridge()) {
      initializeTheme()
    }
  })

  onMounted(() => {
    checkStorageCompatibility()
    toggleTransition(false)
    // 插件子应用不执行系统升级检测：版本比对、更新日志、清理缓存、强制重登等
    // 均属于基座（主应用）职责，由基座统一管理，这里只拉取系统基础信息。
    systemStore.ensureSystemInfo().catch(() => {})
  })
</script>
