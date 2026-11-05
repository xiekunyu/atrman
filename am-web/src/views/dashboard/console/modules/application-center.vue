<template>
  <div class="art-card p-5 mb-5 max-sm:mb-4 application-center" v-loading="loading">
    <div class="art-card-header application-center__header">
      <div class="title">
        <h4>应用中心</h4>
        <p
          >共 <span class="text-primary">{{ stats?.total ?? 0 }}</span> 个应用 ·
          <span class="text-primary">{{ apps.length }}</span> 个可直接打开</p
        >
      </div>
      <div class="application-center__aside">
        <div class="application-center__stats">
          <span v-for="item in appStats" :key="item.label" class="application-center__stat">
            <b :class="item.cls">{{ item.value }}</b>
            <label>{{ item.label }}</label>
          </span>
        </div>
        <ElButton text type="primary" :loading="loading" @click="loadAll">
          <template #icon>
            <ArtSvgIcon icon="ri:refresh-line" />
          </template>
          刷新
        </ElButton>
      </div>
    </div>

    <ElEmpty :image-size="80" v-if="!loading && apps.length === 0" description="暂无可用应用" />

    <ElScrollbar v-else max-height="260px">
      <div class="application-center__grid">
        <button
          v-for="plugin in apps"
          :key="plugin.id || plugin.name"
          class="application-center__item"
          type="button"
          @click="openPlugin(plugin)"
        >
          <span class="application-center__icon" :style="getPluginIconStyle(plugin)">
            <img
              v-if="isImagePluginIcon(plugin)"
              class="application-center__icon-img"
              :src="getPluginIcon(plugin)"
              :alt="plugin.title || plugin.name"
              @error="handlePluginIconError(plugin)"
            />
            <ArtSvgIcon v-else :icon="getPluginIcon(plugin)" />
          </span>
          <span class="application-center__name">{{ plugin.title || plugin.name }}</span>
        </button>
      </div>
    </ElScrollbar>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import pluginApi from '@/api/plugin/app'
  import dashboardApi, { type AppStats } from '@/api/system/dashboard'
  import {
    getPluginIcon as resolvePluginIcon,
    getPluginIconKey,
    getPluginIconStyle as resolvePluginIconStyle,
    hasPluginEntry as hasManageEntry,
    isImagePluginIcon as resolveIsImagePluginIcon,
    type PluginRecord
  } from '@/utils/plugin'

  const router = useRouter()
  const loading = ref(false)
  const plugins = ref<PluginRecord[]>([])
  const stats = ref<AppStats | null>(null)
  const failedIconKeys = ref<Set<string>>(new Set())

  const apps = computed(() =>
    plugins.value.filter(
      (plugin) =>
        Number(plugin.status) === 1 &&
        Number(plugin.is_dev || 0) !== 1 &&
        Number(plugin.type || 1) === 1 &&
        [1, 2].includes(Number(plugin.platform || 1))
    )
  )

  // 头部状态统计：数字配色沿用原应用概况卡片
  const appStats = computed(() => {
    const errorCount = stats.value?.counts.error ?? 0
    return [
      { label: '已启用', value: stats.value?.counts.enabled ?? 0, cls: 'text-success' },
      { label: '待启用', value: stats.value?.counts.installed ?? 0, cls: 'text-theme' },
      { label: '开发中', value: stats.value?.dev ?? 0, cls: 'text-warning' },
      {
        label: '异常',
        value: errorCount,
        cls: errorCount > 0 ? 'text-danger' : 'text-g-400'
      }
    ]
  })

  onMounted(() => {
    loadAll()
  })

  const loadAll = async () => {
    loading.value = true
    await Promise.allSettled([loadPlugins(), loadStats()])
    loading.value = false
  }

  // 首页应用中心只取“有可见入口”的应用（纯插件无页面不展示）
  const loadPlugins = async () => {
    const result = await pluginApi.list({ visible_entry: 1 })
    plugins.value = Array.isArray(result) ? result : []
  }

  const loadStats = async () => {
    try {
      stats.value = await dashboardApi.appStats()
    } catch {
      // 错误提示由 HTTP 拦截器统一处理，统计区保持 0
    }
  }

  const hasPluginEntry = (plugin: PluginRecord) => hasManageEntry(plugin)

  const openPlugin = (plugin: PluginRecord) => {
    if (!hasPluginEntry(plugin)) {
      ElMessage.warning('插件未配置应用入口')
      return
    }

    router.push({
      name: 'PluginApp',
      params: { name: plugin.name },
      query: { title: plugin.title || plugin.name }
    })
  }

  const getPluginIcon = (plugin: PluginRecord) => resolvePluginIcon(plugin, failedIconKeys.value)

  const isImagePluginIcon = (plugin: PluginRecord) =>
    resolveIsImagePluginIcon(plugin, failedIconKeys.value)

  const handlePluginIconError = (plugin: PluginRecord) => {
    failedIconKeys.value = new Set([...failedIconKeys.value, getPluginIconKey(plugin)])
  }

  const getPluginIconStyle = (plugin: PluginRecord) =>
    resolvePluginIconStyle(plugin, failedIconKeys.value)
</script>

<style scoped lang="scss">
  .application-center {
    min-height: 190px;
  }

  .application-center__header {
    align-items: center;
    flex-wrap: wrap;
    row-gap: 10px;
    margin-bottom: 18px;

    h4 {
      margin: 0 0 6px;
      font-size: 16px;
      font-weight: 600;
    }

    p {
      margin: 0;
      color: var(--art-gray-600);
    }
  }

  .application-center__aside {
    display: flex;
    flex-shrink: 0;
    align-items: center;
  }

  .application-center__stats {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-right: 14px;
  }

  .application-center__stat {
    display: inline-flex;
    align-items: baseline;
    gap: 4px;

    b {
      font-size: 17px;
      font-weight: 600;
      line-height: 1;
    }

    label {
      font-size: 12px;
      color: var(--art-gray-500);
    }
  }

  .application-center__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
    gap: 18px 14px;
    padding: 2px 4px 6px;
  }

  .application-center__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    min-width: 0;
    padding: 12px 8px;
    color: inherit;
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 16px;
    transition: all 0.2s ease;

    &:hover {
      background: var(--el-fill-color-lighter);
      transform: translateY(-2px);
    }
  }

  .application-center__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    overflow: hidden;
    font-size: 28px;
    color: #fff;
    border-radius: 16px;
    box-shadow: 0 10px 22px rgb(0 0 0 / 10%);
  }

  .application-center__icon-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .application-center__name {
    display: -webkit-box;
    max-width: 100%;
    overflow: hidden;
    font-size: 13px;
    line-height: 1.35;
    color: var(--art-gray-900);
    text-align: center;
    text-overflow: ellipsis;
    word-break: break-all;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  @media screen and (max-width: 640px) {
    .application-center__stats {
      gap: 12px;
      margin-right: 8px;
    }

    .application-center__grid {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px 8px;
    }

    .application-center__icon {
      width: 50px;
      height: 50px;
      font-size: 25px;
      border-radius: 14px;
    }
  }
</style>
