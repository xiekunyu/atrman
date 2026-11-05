<template>
  <div class="plugin-app-page">
    <ElAlert
      v-if="message"
      :title="message"
      :type="messageType"
      :closable="false"
      show-icon
      class="plugin-app-message"
    />
    <iframe v-if="iframeUrl" :src="iframeUrl" class="plugin-app-iframe" />
    <div v-else class="plugin-app-stage">
      <div
        :key="containerKey"
        ref="containerRef"
        class="plugin-app-container"
        :class="{ 'is-mounted': mounted }"
      />
      <Transition name="plugin-fade">
        <div v-if="loading" class="plugin-app-loading">
          <span class="plugin-app-spinner" />
          <span class="plugin-app-loading-text">正在加载应用…</span>
        </div>
      </Transition>
    </div>
    <PluginCapabilityHost ref="capabilityHostRef" />
  </div>
</template>

<script setup lang="ts">
  import { loadMicroApp, type MicroApp } from 'qiankun'
  import PluginCapabilityHost from '@/bridge/plugin/components/PluginCapabilityHost.vue'
  import { createPluginBridgeHost } from '@/bridge/plugin/host'
  import type { PluginBridgeHost, PluginCapabilityHostExpose } from '@/bridge/plugin/types'
  import { useWorktabStore } from '@/store/modules/worktab'
  import { useSettingStore } from '@/store/modules/setting'
  import { loadPluginDetail, resolveMicroEntry, markPluginLoaded } from '@/utils/pluginMicroApp'

  defineOptions({ name: 'PluginApp' })

  const route = useRoute()
  const worktabStore = useWorktabStore()
  const settingStore = useSettingStore()
  const containerRef = ref<HTMLElement>()
  const capabilityHostRef = ref<PluginCapabilityHostExpose>()
  const loading = ref(false)
  const mounted = ref(false)
  const iframeUrl = ref('')
  const message = ref('')
  const messageType = ref<'success' | 'warning' | 'info' | 'error'>('info')
  const containerKey = ref(0)
  let microApp: MicroApp | null = null
  let bridgeHost: PluginBridgeHost | null = null
  let mountId = 0
  let mountedKey = ''

  const destroyBridgeHost = () => {
    bridgeHost?.destroy()
    bridgeHost = null
  }

  const unloadMicroApp = async () => {
    mounted.value = false
    mountedKey = ''
    const app = microApp
    microApp = null
    destroyBridgeHost()
    if (!app) return
    await app.unmount()
  }

  const showMessage = (text: string, type: typeof messageType.value = 'warning') => {
    message.value = text
    messageType.value = type
  }

  const setRouteTitle = (title: string) => {
    route.meta.title = title
    route.meta.tabTitle = title
    worktabStore.updateTabTitle(route.fullPath, title)
  }

  // 读取基座当前主题快照（只同步主题，不含菜单布局 menuType）
  const readBaseTheme = () => ({
    systemThemeType: settingStore.systemThemeType,
    systemThemeMode: settingStore.systemThemeMode,
    systemThemeColor: settingStore.systemThemeColor,
    menuThemeType: settingStore.menuThemeType,
    customRadius: settingStore.customRadius
  })

  const loadPluginApp = async () => {
    const name = String(route.params.name || '')
    const entryType = String(route.query.entry || 'manage')
    const queryTitle = String(route.query.title || '')
    if (queryTitle) {
      setRouteTitle(queryTitle)
    }
    if (!name) {
      showMessage('插件参数不完整')
      return
    }

    // 同一插件同入口已挂载（如 worktab 标签切回）直接跳过，避免重新下载整包与重建容器
    const mountKey = `${name}::${entryType}`
    if (microApp && mountedKey === mountKey) return

    const currentMountId = ++mountId
    await unloadMicroApp()
    if (currentMountId !== mountId) return
    containerKey.value += 1

    loading.value = true
    mounted.value = false
    iframeUrl.value = ''
    message.value = ''

    try {
      // 缓存穿透：命中会话缓存则免去阻塞式详情请求
      const detail = await loadPluginDetail(name)
      if (currentMountId !== mountId) return
      setRouteTitle(detail.title || detail.name || '插件应用')
      if (Number(detail.status) !== 1) {
        showMessage('插件未启用，无法打开')
        return
      }
      if (Number(detail.platform || 1) === 3) {
        showMessage('该应用仅支持移动端')
        return
      }

      const resolved = resolveMicroEntry(detail, entryType === 'app' ? 'app' : 'manage')
      if (!resolved.entry) {
        showMessage(entryType === 'app' ? '插件未配置应用入口' : '插件未配置管理入口')
        return
      }
      if (resolved.isIframe) {
        iframeUrl.value = resolved.absoluteEntry
        return
      }

      await nextTick()
      if (currentMountId !== mountId) return
      if (!containerRef.value) {
        showMessage('插件容器初始化失败', 'error')
        return
      }
      containerRef.value.innerHTML = ''

      const appName = String(detail.name || name)
      bridgeHost = createPluginBridgeHost({
        plugin: detail,
        pluginName: name,
        entryUrl: resolved.absoluteEntry,
        manifest: resolved.manifest,
        setTitle: setRouteTitle,
        getCapabilityHost: () => capabilityHostRef.value,
        getTheme: readBaseTheme,
        watchTheme: (cb) =>
          watch(
            () => [
              settingStore.systemThemeType,
              settingStore.systemThemeMode,
              settingStore.systemThemeColor,
              settingStore.menuThemeType,
              settingStore.customRadius
            ],
            () => cb(readBaseTheme())
          )
      })
      const app = loadMicroApp(
        {
          name: appName,
          // 使用与门户页预取完全一致的 versionedEntryUrl，确保命中 qiankun/浏览器缓存
          entry: resolved.versionedEntryUrl,
          container: containerRef.value,
          props: {
            plugin: detail,
            pluginName: name,
            // 双入口架构：告知子应用当前是前台入口还是管理入口
            // （子应用 sdk/entry.ts 优先读此值，dev 模式入口 HTML 无注入标识时依赖它）
            entryType: entryType === 'app' ? 'front' : 'manage',
            hostBridge: bridgeHost.publicApi,
            amBridge: bridgeHost.publicApi,
            onMessage: bridgeHost.handleLegacyMessage
          }
        },
        {
          sandbox: {
            strictStyleIsolation: false,
            // 给子应用所有全局样式加容器前缀(div[data-qiankun])，
            // 防止子应用 barrel 注入的全局 Element Plus / 框架样式泄漏覆盖基座菜单。
            experimentalStyleIsolation: true
          }
        }
      )
      microApp = app
      markPluginLoaded(appName)
      mountedKey = mountKey

      await app.mountPromise
      if (currentMountId !== mountId) {
        await app.unmount()
        if (microApp === app) {
          microApp = null
          mountedKey = ''
        }
        return
      }
      // 挂载完成：触发容器淡入过渡动画
      mounted.value = true
    } catch (error) {
      console.error('[PluginApp] 插件子应用加载失败:', error)
      showMessage('插件加载失败，请检查入口地址、运行状态或控制台错误', 'error')
    } finally {
      if (currentMountId === mountId) loading.value = false
    }
  }

  watch(
    () => [route.params.name, route.query.entry],
    () => loadPluginApp(),
    { immediate: true }
  )

  onBeforeUnmount(() => {
    mountId += 1
    unloadMicroApp()
  })
</script>

<style scoped lang="scss">
  .plugin-app-page {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    min-height: var(--art-full-height, calc(100vh - 120px));
    background: var(--el-bg-color);
  }

  .plugin-app-message {
    position: fixed;
    top: 16px;
    left: 50%;
    z-index: 10;
    width: min(560px, calc(100vw - 32px));
    transform: translateX(-50%);
  }

  .plugin-app-stage {
    position: relative;
    flex: 1;
    width: 100%;
    min-height: var(--art-full-height, calc(100vh - 120px));
  }

  .plugin-app-container {
    width: 100%;
    height: 100%;
    min-height: inherit;
    opacity: 0;
    transform: translateY(10px);
    transition:
      opacity 0.42s ease,
      transform 0.42s ease;
  }

  .plugin-app-container.is-mounted {
    opacity: 1;
    transform: translateY(0);
  }

  .plugin-app-loading {
    position: absolute;
    inset: 0;
    z-index: 5;
    display: flex;
    flex-direction: column;
    gap: 16px;
    align-items: center;
    justify-content: center;
    background: var(--el-bg-color);
  }

  .plugin-app-spinner {
    width: 34px;
    height: 34px;
    border: 3px solid var(--el-border-color-light);
    border-top-color: var(--el-color-primary);
    border-radius: 50%;
    animation: plugin-spin 0.8s linear infinite;
  }

  .plugin-app-loading-text {
    font-size: 14px;
    color: var(--el-text-color-secondary);
  }

  .plugin-fade-enter-active,
  .plugin-fade-leave-active {
    transition: opacity 0.3s ease;
  }

  .plugin-fade-enter-from,
  .plugin-fade-leave-to {
    opacity: 0;
  }

  @keyframes plugin-spin {
    to {
      transform: rotate(360deg);
    }
  }

  .plugin-app-iframe {
    width: 100%;
    height: 100%;
    min-height: var(--art-full-height, calc(100vh - 120px));
    border: 0;
  }
</style>
