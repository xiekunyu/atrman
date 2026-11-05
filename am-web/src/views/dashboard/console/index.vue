<!-- 工作台页面 -->
<template>
  <div class="dashboard-console">
    <ElContainer class="dashboard-layout">
      <ElMain class="dashboard-layout__main">
        <div class="dashboard-toolbar art-card">
          <div>
            <h3 class="dashboard-toolbar__title">工作台</h3>
            <p class="dashboard-toolbar__desc">
              <template v-if="isEditing">编辑模式：拖拽调整排序，修改部件尺寸</template>
              <template v-else>自定义工作台，支持增删、拖拽、修改尺寸</template>
            </p>
          </div>
          <div class="dashboard-toolbar__actions">
            <ElButton v-if="!isEditing" type="primary" @click="enterEditMode">编辑布局</ElButton>
            <template v-if="isEditing">
              <ElButton @click="resetDashboardLayout">重置布局</ElButton>
              <ElButton @click="cancelDashboardLayout">取消</ElButton>
              <ElButton type="success" @click="saveDashboardLayout">保存</ElButton>
            </template>
          </div>
        </div>

        <div
          ref="masonryContainerRef"
          class="dashboard-masonry"
          :class="{ 'dashboard-masonry--editing': isEditing }"
        >
          <div
            v-for="widget in dashboardWidgets"
            :key="widget.id"
            :ref="setWidgetItemRef(widget.id)"
            class="dashboard-masonry__item"
            :class="isEditing ? 'dashboard-widget-col' : ''"
          >
            <!-- 编辑模式 -->
            <div v-if="isEditing" class="dashboard-widget dashboard-widget--editing">
              <div class="dashboard-widget__mask">
                <ArtSvgIcon icon="ri:drag-move-line" class="dashboard-widget__drag" />
                <div class="dashboard-widget__name">{{ widget.title }}</div>
              </div>
              <div class="dashboard-widget__toolbar">
                <ElDropdown
                  @command="(val: SizeKey) => changeWidgetSize(widget, val)"
                  placement="bottom-end"
                >
                  <ArtSvgIcon icon="ri:aspect-ratio-fill" class="dashboard-widget__resize" />
                  <template #dropdown>
                    <ElDropdownMenu>
                      <ElDropdownItem
                        v-for="option in sizeOptions"
                        :key="option.value"
                        :command="option.value"
                      >
                        {{ option.label }}
                      </ElDropdownItem>
                    </ElDropdownMenu>
                  </template>
                </ElDropdown>
                <ElButton
                  circle
                  link
                  type="danger"
                  size="small"
                  class="dashboard-widget__remove"
                  @click="removeWidget(widget.id)"
                >
                  <ArtSvgIcon icon="ri:close-line" />
                </ElButton>
              </div>
            </div>

            <!-- 浏览模式 -->
            <div v-else class="dashboard-widget">
              <AmRemoteVue
                v-if="widget.source === 'remote'"
                :meta="widget.meta"
                :component-props="{ source: 'dashboard-console', widget }"
                force
              />
              <component v-else :is="widgetComponents[widget.type]" />
            </div>
          </div>
        </div>

        <ElEmpty v-if="dashboardWidgets.length === 0" description="暂无部件，请添加部件" />
      </ElMain>

      <ElAside v-if="catalogVisible" class="dashboard-layout__aside" width="380px">
        <div class="widget-catalog-panel art-card">
          <div class="widget-catalog-panel__header">
            <div>
              <h3>添加部件</h3>
              <p>选择需要添加到工作台的部件</p>
            </div>
          </div>

          <div v-loading="loadingRemoteWidgets" class="widget-catalog">
            <ElRow :gutter="12" class="widget-catalog__row">
              <ElCol
                v-for="item in widgetCatalog"
                :key="item.type"
                :span="12"
                class="widget-catalog__col"
              >
                <div class="widget-catalog__item">
                  <div class="widget-catalog__body">
                    <div class="widget-catalog__header">
                      <h4>{{ item.title }}</h4>
                      <span
                        class="widget-catalog__source"
                        :class="`widget-catalog__source--${item.source}`"
                      >
                        {{ item.source === 'remote' ? '远程' : '本地' }}
                      </span>
                    </div>
                    <p>{{ item.desc }}</p>
                    <small v-if="item.source === 'remote'" class="widget-catalog__provider">
                      由 {{ item.meta.plugin_name || '未知应用' }} 提供
                    </small>
                  </div>
                  <ElButton
                    type="primary"
                    size="small"
                    class="widget-catalog__add"
                    @click="addWidget(item)"
                  >
                    添加
                  </ElButton>
                </div>
              </ElCol>
            </ElRow>
          </div>
        </div>
      </ElAside>
    </ElContainer>
  </div>
</template>

<script setup lang="ts">
  import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
  import type { Component, ComponentPublicInstance } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useDraggable } from 'vue-draggable-plus'
  import CardList from './modules/card-list.vue'
  import LoginTrend from './modules/login-trend.vue'
  import ServerHealth from './modules/server-health.vue'
  import StorageUsage from './modules/storage-usage.vue'
  import NewUser from './modules/new-user.vue'
  import Dynamic from './modules/dynamic-stats.vue'
  import TodoList from './modules/todo-list.vue'
  import ApplicationCenter from './modules/application-center.vue'
  import AmRemoteVue from '@/components/business/am-remote-vue/index.vue'
  import pluginApi from '@/api/plugin/app'
  import { remoteVueLoader } from '@/utils/remote-vue'
  import type { RemoteVueComponentMeta } from '@/types/remote-vue'

  defineOptions({ name: 'Console' })

  type LocalWidgetType =
    | 'cardList'
    | 'applicationCenter'
    | 'loginTrend'
    | 'serverHealth'
    | 'storageUsage'
    | 'newUser'
    | 'dynamic'
    | 'todoList'

  type SizeKey = 'quarter' | 'third' | 'half' | 'twoThirds' | 'oneSixth' | 'full'

  interface WidgetSize {
    sm: number
    md: number
    lg: number
  }

  interface BaseDashboardWidget {
    id: string
    type: string
    title: string
    sizeKey: SizeKey
    size: WidgetSize
  }

  interface LocalDashboardWidget extends BaseDashboardWidget {
    source: 'local'
    type: LocalWidgetType
  }

  interface RemoteDashboardWidget extends BaseDashboardWidget {
    source: 'remote'
    meta: RemoteVueComponentMeta
  }

  type DashboardWidget = LocalDashboardWidget | RemoteDashboardWidget

  interface BaseWidgetCatalogItem {
    type: string
    title: string
    desc: string
    sizeKey: SizeKey
  }

  interface LocalWidgetCatalogItem extends BaseWidgetCatalogItem {
    source: 'local'
    type: LocalWidgetType
  }

  interface RemoteWidgetCatalogItem extends BaseWidgetCatalogItem {
    source: 'remote'
    meta: RemoteVueComponentMeta
  }

  type WidgetCatalogItem = LocalWidgetCatalogItem | RemoteWidgetCatalogItem

  type RemoteWidgetRow = RemoteVueComponentMeta & {
    app_name?: string
    size?: unknown
    status?: number | string
  }

  const sizeOptions: Array<{ label: string; value: SizeKey; size: WidgetSize }> = [
    { label: '1/6', value: 'oneSixth', size: { sm: 24, md: 12, lg: 4 } },
    { label: '1/4', value: 'quarter', size: { sm: 24, md: 12, lg: 6 } },
    { label: '1/3', value: 'third', size: { sm: 24, md: 12, lg: 8 } },
    { label: '1/2', value: 'half', size: { sm: 24, md: 12, lg: 12 } },
    { label: '2/3', value: 'twoThirds', size: { sm: 24, md: 24, lg: 16 } },
    { label: '整行', value: 'full', size: { sm: 24, md: 24, lg: 24 } }
  ]

  const widgetComponents: Record<LocalWidgetType, Component> = {
    cardList: CardList,
    applicationCenter: ApplicationCenter,
    loginTrend: LoginTrend,
    serverHealth: ServerHealth,
    storageUsage: StorageUsage,
    newUser: NewUser,
    dynamic: Dynamic,
    todoList: TodoList
  }

  const localWidgetCatalog: LocalWidgetCatalogItem[] = [
    {
      source: 'local',
      type: 'cardList',
      title: '核心指标',
      desc: '用户、登录、应用、存储等关键指标',
      sizeKey: 'full'
    },
    {
      source: 'local',
      type: 'applicationCenter',
      title: '应用中心',
      desc: '快速打开已启用应用，含应用状态统计',
      sizeKey: 'full'
    },
    {
      source: 'local',
      type: 'loginTrend',
      title: '登录趋势',
      desc: '近 30 天登录成功/失败走势',
      sizeKey: 'half'
    },
    {
      source: 'local',
      type: 'serverHealth',
      title: '系统健康',
      desc: 'CPU、内存、磁盘与服务状态',
      sizeKey: 'quarter'
    },
    {
      source: 'local',
      type: 'storageUsage',
      title: '存储用量',
      desc: '按文件类型的容量占比',
      sizeKey: 'quarter'
    },
    {
      source: 'local',
      type: 'newUser',
      title: '新增用户',
      desc: '最近注册用户',
      sizeKey: 'third'
    },
    {
      source: 'local',
      type: 'dynamic',
      title: '系统动态',
      desc: '最近业务操作记录',
      sizeKey: 'third'
    },
    {
      source: 'local',
      type: 'todoList',
      title: '消息待办',
      desc: '未读站内信速览',
      sizeKey: 'third'
    }
  ]

  const createWidget = (item: WidgetCatalogItem, id?: string): DashboardWidget => {
    const base = {
      id: id || `${item.type}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      title: item.title,
      sizeKey: item.sizeKey,
      size: getSizeByKey(item.sizeKey)
    }

    if (item.source === 'remote') {
      return {
        ...base,
        source: 'remote',
        type: item.type,
        meta: { ...item.meta }
      }
    }

    return {
      ...base,
      source: 'local',
      type: item.type
    }
  }

  const defaultWidgets: DashboardWidget[] = [
    createWidget(localWidgetCatalog[0], 'card-list'),
    createWidget(localWidgetCatalog[1], 'application-center'),
    createWidget(localWidgetCatalog[2], 'login-trend'),
    createWidget(localWidgetCatalog[3], 'server-health'),
    createWidget(localWidgetCatalog[4], 'storage-usage'),
    createWidget(localWidgetCatalog[5], 'new-user'),
    createWidget(localWidgetCatalog[6], 'dynamic'),
    createWidget(localWidgetCatalog[7], 'todo-list')
  ]

  const isEditing = ref(false)
  const catalogVisible = ref(false)
  const loadingRemoteWidgets = ref(false)
  const masonryContainerRef = ref<HTMLElement | null>(null)
  const widgetItemRefs = new Map<string, HTMLElement>()
  const remoteWidgetCatalog = ref<RemoteWidgetCatalogItem[]>([])
  const layoutLoadedFromStorage = ref(false)
  const dashboardWidgets = ref<DashboardWidget[]>(cloneWidgets(defaultWidgets))
  const widgetCatalog = computed<WidgetCatalogItem[]>(() => [
    ...localWidgetCatalog,
    ...remoteWidgetCatalog.value
  ])

  const LAYOUT_STORAGE_KEY = 'dashboard_layout'

  useDraggable(masonryContainerRef, dashboardWidgets, {
    animation: 150,
    handle: '.dashboard-widget__drag',
    ghostClass: 'sortable-ghost'
  })

  /**
   * 瀑布流布局：沿用 Element Plus 的 24 栅格尺寸与 sm/md/lg 断点，
   * 浏览模式下按「最短可用轨道」绝对定位每个部件，矮卡片下方不再留白；
   * 编辑模式退回静态 flex 栅格，保证拖拽排序与尺寸调整可用
   */
  const MASONRY_COLUMNS = 24
  const breakpointMql = {
    lg: window.matchMedia('(min-width: 1200px)'),
    md: window.matchMedia('(min-width: 992px)')
  }
  let resizeObserver: ResizeObserver | null = null
  let rafId = 0

  type Breakpoint = keyof WidgetSize

  function getBreakpoint(): Breakpoint {
    if (breakpointMql.lg.matches) return 'lg'
    if (breakpointMql.md.matches) return 'md'
    return 'sm'
  }

  function setWidgetItemRef(widgetId: string) {
    return (el: Element | ComponentPublicInstance | null) => {
      const node =
        el instanceof HTMLElement
          ? el
          : (((el as ComponentPublicInstance | null)?.$el as HTMLElement | undefined) ?? null)

      const old = widgetItemRefs.get(widgetId)
      if (old && old !== node) {
        resizeObserver?.unobserve(old)
      }

      if (node) {
        widgetItemRefs.set(widgetId, node)
        resizeObserver?.observe(node)
      } else {
        widgetItemRefs.delete(widgetId)
      }
    }
  }

  function scheduleRelayout() {
    if (isEditing.value) return
    cancelAnimationFrame(rafId)
    rafId = requestAnimationFrame(() => {
      rafId = 0
      relayout()
    })
  }

  function relayout() {
    const container = masonryContainerRef.value
    if (!container || isEditing.value) return

    const containerWidth = container.clientWidth
    if (!containerWidth) return

    const breakpoint = getBreakpoint()
    // 每条栅格轨道的当前累计高度，部件始终落入可容纳它的最矮轨道组合
    const tracks = new Array<number>(MASONRY_COLUMNS).fill(0)
    let containerHeight = 0

    for (const widget of dashboardWidgets.value) {
      const el = widgetItemRefs.get(widget.id)
      if (!el) continue

      const span = Math.max(1, Math.min(widget.size[breakpoint], MASONRY_COLUMNS))
      // 与旧 ElCol 宽度完全一致：span/24 容器宽，横向间距靠 item 的左右内边距
      el.style.width = `${(span / MASONRY_COLUMNS) * containerWidth}px`
      // 宽度写入后读取高度会强制同步回流，拿到的即卡片按内容撑开的真实高度
      const height = el.offsetHeight

      let bestStart = 0
      let bestTop = Number.POSITIVE_INFINITY
      for (let start = 0; start <= MASONRY_COLUMNS - span; start++) {
        let top = 0
        for (let i = start; i < start + span; i++) {
          top = Math.max(top, tracks[i])
        }
        if (top < bestTop) {
          bestTop = top
          bestStart = start
        }
      }

      el.style.left = `${(bestStart / MASONRY_COLUMNS) * containerWidth}px`
      el.style.top = `${bestTop}px`

      const bottom = bestTop + height
      for (let i = bestStart; i < bestStart + span; i++) {
        tracks[i] = bottom
      }
      containerHeight = Math.max(containerHeight, bottom)
    }

    container.style.height = `${containerHeight}px`
    container.style.visibility = 'visible'
  }

  function clearMasonryStyles() {
    const container = masonryContainerRef.value
    if (container) container.style.height = ''
    widgetItemRefs.forEach((el) => {
      el.style.width = ''
      el.style.left = ''
      el.style.top = ''
      el.style.removeProperty('--widget-span')
    })
  }

  function applyEditLayout() {
    const breakpoint = getBreakpoint()
    dashboardWidgets.value.forEach((widget) => {
      widgetItemRefs
        .get(widget.id)
        ?.style.setProperty('--widget-span', String(widget.size[breakpoint]))
    })
  }

  function handleBreakpointChange() {
    if (isEditing.value) {
      applyEditLayout()
    } else {
      scheduleRelayout()
    }
  }

  watch(
    dashboardWidgets,
    () => {
      nextTick(() => {
        if (isEditing.value) {
          applyEditLayout()
        } else {
          scheduleRelayout()
        }
      })
    },
    { deep: true }
  )

  onMounted(async () => {
    resizeObserver = new ResizeObserver(() => scheduleRelayout())
    if (masonryContainerRef.value) {
      resizeObserver.observe(masonryContainerRef.value)
    }
    breakpointMql.lg.addEventListener('change', handleBreakpointChange)
    breakpointMql.md.addEventListener('change', handleBreakpointChange)

    loadDashboardLayout()
    const loaded = await loadRemoteWidgets()
    if (loaded) {
      pruneStaleRemoteWidgets()
    }
    if (!layoutLoadedFromStorage.value) {
      mergeRemoteDefaultWidgets()
    }

    await nextTick()
    scheduleRelayout()
  })

  onUnmounted(() => {
    cancelAnimationFrame(rafId)
    resizeObserver?.disconnect()
    breakpointMql.lg.removeEventListener('change', handleBreakpointChange)
    breakpointMql.md.removeEventListener('change', handleBreakpointChange)
  })

  function getSizeByKey(key: SizeKey): WidgetSize {
    const option = sizeOptions.find((item) => item.value === key)!
    return { ...option.size }
  }

  function isRemoteWidgetMeta(value: unknown): value is RemoteWidgetRow {
    if (!value || typeof value !== 'object') return false

    const item = value as Record<string, any>
    return item.type === 'widget' && Boolean(item.name) && Boolean(item.url)
  }

  function normalizeRemoteWidgetMeta(row: RemoteWidgetRow): RemoteVueComponentMeta {
    return {
      ...row,
      plugin: row.plugin || row.app_name,
      name: String(row.name),
      title: row.title || row.name,
      type: 'widget',
      url: String(row.url),
      preload: row.preload ?? true
    }
  }

  function getRemoteWidgetType(meta: RemoteVueComponentMeta & { app_name?: string }) {
    const namespace = meta.plugin || meta.app_name || encodeURIComponent(meta.url)
    return `remote:${namespace}:${meta.name}`
  }

  function normalizeRemoteWidgetSizeKey(size: unknown): SizeKey {
    if (sizeOptions.some((item) => item.value === size)) return size as SizeKey

    const normalized = String(size || '').toLowerCase()
    const sizeMap: Record<string, SizeKey> = {
      '4': 'oneSixth',
      '1/6': 'oneSixth',
      onesix: 'oneSixth',
      '6': 'quarter',
      '1/4': 'quarter',
      quarter: 'quarter',
      '8': 'third',
      '1/3': 'third',
      third: 'third',
      '12': 'half',
      '1/2': 'half',
      half: 'half',
      '16': 'twoThirds',
      '2/3': 'twoThirds',
      twothirds: 'twoThirds',
      '24': 'full',
      full: 'full',
      整行: 'full'
    }

    return sizeMap[normalized] || 'third'
  }

  function createRemoteCatalogItem(row: RemoteWidgetRow): RemoteWidgetCatalogItem {
    const meta = normalizeRemoteWidgetMeta(row)
    return {
      source: 'remote',
      type: getRemoteWidgetType(meta),
      title: meta.title || meta.name,
      desc: meta.description || (meta.plugin ? `${meta.plugin} 远程小部件` : '远程小部件'),
      sizeKey: normalizeRemoteWidgetSizeKey(row.size),
      meta
    }
  }

  function cloneWidgets(widgets: DashboardWidget[]) {
    return widgets.map((widget) => ({
      ...widget,
      size: { ...widget.size },
      ...(widget.source === 'remote' ? { meta: { ...widget.meta } } : {})
    }))
  }

  /** 旧版本地部件迁移：已改造的部件重命名，已删除的部件（如销售概览、应用概况）直接剔除 */
  const legacyLocalWidgetMap: Record<string, LocalWidgetType> = {
    activeUser: 'loginTrend'
  }

  function migrateStoredWidgets(widgets: DashboardWidget[]): DashboardWidget[] {
    return widgets
      .map((widget) => {
        if (widget.source !== 'local') return widget
        const mapped = legacyLocalWidgetMap[widget.type]
        if (!mapped) return widget
        const catalogItem = localWidgetCatalog.find((item) => item.type === mapped)
        return {
          ...widget,
          type: mapped,
          title: catalogItem?.title ?? widget.title
        }
      })
      .filter(
        (widget) =>
          widget.source === 'remote' ||
          Object.prototype.hasOwnProperty.call(widgetComponents, widget.type)
      )
  }

  function isDashboardWidget(value: unknown): value is DashboardWidget {
    if (!value || typeof value !== 'object') return false

    const widget = value as Record<string, any>
    return (
      typeof widget.id === 'string' &&
      typeof widget.type === 'string' &&
      typeof widget.title === 'string' &&
      sizeOptions.some((item) => item.value === widget.sizeKey) &&
      (widget.source === 'local' || (widget.source === 'remote' && Boolean(widget.meta)))
    )
  }

  function createRemoteDefaultWidgets() {
    return remoteWidgetCatalog.value.map((item) => createWidget(item, item.type))
  }

  function mergeRemoteDefaultWidgets() {
    const existingTypes = new Set(dashboardWidgets.value.map((widget) => widget.type))
    const remoteWidgets = createRemoteDefaultWidgets().filter(
      (widget) => !existingTypes.has(widget.type)
    )

    if (remoteWidgets.length) {
      dashboardWidgets.value = [...dashboardWidgets.value, ...remoteWidgets]
    }
  }

  function loadDashboardLayout() {
    layoutLoadedFromStorage.value = false

    try {
      const storedLayout = localStorage.getItem(LAYOUT_STORAGE_KEY)
      if (!storedLayout) {
        dashboardWidgets.value = cloneWidgets(defaultWidgets)
        return
      }

      const widgets = JSON.parse(storedLayout)
      if (!Array.isArray(widgets) || !widgets.every(isDashboardWidget)) {
        dashboardWidgets.value = cloneWidgets(defaultWidgets)
        return
      }

      const migrated = migrateStoredWidgets(widgets as DashboardWidget[])
      if (!migrated.length) {
        dashboardWidgets.value = cloneWidgets(defaultWidgets)
        return
      }

      dashboardWidgets.value = cloneWidgets(migrated)
      layoutLoadedFromStorage.value = true
    } catch {
      dashboardWidgets.value = cloneWidgets(defaultWidgets)
    }
  }

  async function loadRemoteWidgets() {
    loadingRemoteWidgets.value = true
    try {
      const result = await pluginApi.widgetList({ type: 'widget', status: 1 })
      const list = Array.isArray(result) ? result : []
      remoteWidgetCatalog.value = list.filter(isRemoteWidgetMeta).map(createRemoteCatalogItem)
      return true
    } catch (error) {
      console.error('[Dashboard] load remote widgets failed:', error)
      remoteWidgetCatalog.value = []
      ElMessage.warning('远程小部件加载失败')
      return false
    } finally {
      loadingRemoteWidgets.value = false
    }
  }

  /**
   * 清理失效的远程部件：应用被卸载后后端不再下发其小部件，
   * 但布局缓存（dashboard_layout）与编译源码缓存仍保留，这里一并移除
   */
  function pruneStaleRemoteWidgets() {
    const aliveTypes = new Set(remoteWidgetCatalog.value.map((item) => item.type))
    const stale = dashboardWidgets.value.filter(
      (widget) => widget.source === 'remote' && !aliveTypes.has(widget.type)
    )

    if (!stale.length) return

    const kept = dashboardWidgets.value.filter((widget) => !stale.includes(widget))

    // 全部远程部件都失效时回落到默认布局，避免首页空白
    if (!kept.length) {
      layoutLoadedFromStorage.value = false
      dashboardWidgets.value = cloneWidgets(defaultWidgets)
    } else {
      dashboardWidgets.value = kept
    }
    persistDashboardLayout()

    stale.forEach((widget) => {
      if (widget.source !== 'remote') return
      const plugin = widget.meta.plugin
      if (plugin) remoteVueLoader.clearByPlugin(plugin)
    })
  }

  function enterEditMode() {
    isEditing.value = true
    catalogVisible.value = true
    nextTick(() => {
      clearMasonryStyles()
      applyEditLayout()
    })
  }

  function addWidget(item: WidgetCatalogItem) {
    dashboardWidgets.value.push(createWidget(item))
  }

  function removeWidget(id: string) {
    dashboardWidgets.value = dashboardWidgets.value.filter((widget) => widget.id !== id)
  }

  function changeWidgetSize(widget: DashboardWidget, sizeKey: SizeKey) {
    widget.sizeKey = sizeKey
    widget.size = getSizeByKey(sizeKey)
  }

  function resetDashboardLayout() {
    localStorage.removeItem(LAYOUT_STORAGE_KEY)
    layoutLoadedFromStorage.value = false
    dashboardWidgets.value = cloneWidgets([...defaultWidgets, ...createRemoteDefaultWidgets()])
    ElMessage.success('已恢复默认布局')
  }

  function cancelDashboardLayout() {
    loadDashboardLayout()
    if (!layoutLoadedFromStorage.value) {
      dashboardWidgets.value = cloneWidgets([...defaultWidgets, ...createRemoteDefaultWidgets()])
    }
    isEditing.value = false
    catalogVisible.value = false
    nextTick(scheduleRelayout)
  }

  function persistDashboardLayout() {
    const layout = dashboardWidgets.value.map((widget, index) => ({
      id: widget.id,
      type: widget.type,
      title: widget.title,
      sizeKey: widget.sizeKey,
      size: widget.size,
      source: widget.source,
      meta: widget.source === 'remote' ? widget.meta : undefined,
      order: index + 1
    }))

    localStorage.setItem(LAYOUT_STORAGE_KEY, JSON.stringify(layout))
    return layout
  }

  function saveDashboardLayout() {
    persistDashboardLayout()
    ElMessage.success('布局已保存')
    isEditing.value = false
    catalogVisible.value = false
    nextTick(scheduleRelayout)
  }
</script>

<style scoped lang="scss">
  .dashboard-console {
    .dashboard-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 20px;
      margin-bottom: 20px;
    }

    .dashboard-toolbar__title {
      margin: 0 0 8px;
      font-size: 18px;
      font-weight: 600;
    }

    .dashboard-toolbar__desc {
      margin: 0;
      color: var(--art-gray-600);
    }

    .dashboard-toolbar__actions {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .dashboard-layout {
      align-items: flex-start;
      gap: 20px;
    }

    .dashboard-layout__main {
      min-width: 0;
      padding: 0;
    }

    .dashboard-layout__aside {
      flex-shrink: 0;
      overflow: visible;
    }

    .dashboard-masonry {
      position: relative;
      min-height: 120px;
      /* 与旧 ElRow gutter=20 一致的负边距；首次重排前隐藏，避免绝对定位卡片堆叠闪烁 */
      margin: 0 -10px;
      visibility: hidden;
    }

    .dashboard-masonry__item {
      position: absolute;
      top: 0;
      left: 0;
      box-sizing: border-box;
      padding: 0 10px;
    }

    .dashboard-masonry--editing {
      display: flex;
      flex-wrap: wrap;
      visibility: visible;
    }

    .dashboard-masonry--editing .dashboard-masonry__item {
      position: static;
      width: calc(var(--widget-span, 24) / 24 * 100%);
    }

    .dashboard-masonry--editing .dashboard-widget {
      height: 100%;
    }

    .dashboard-widget-col {
      margin-bottom: 20px;
    }

    .dashboard-widget--editing {
      position: relative;
      min-height: 180px;
      background: #fff;
      border: 2px dashed #409eff;
      border-radius: calc(var(--custom-radius) / 2 + 2px);
      cursor: default;
      transition: border-color 0.2s;

      &:hover {
        border-color: #66b1ff;
      }
    }

    .dashboard-widget__mask {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 16px;
      height: 100%;
      min-height: 180px;
      padding: 20px;
    }

    .dashboard-widget__drag {
      font-size: 20px;
      color: #409eff;
      cursor: move;
      margin-top: -20px;
    }

    .dashboard-widget__name {
      padding: 8px 24px;
      background: #409eff;
      color: #fff;
      font-weight: 500;
      border-radius: 20px;
      user-select: none;
    }

    .dashboard-widget__toolbar {
      position: absolute;
      top: 10px;
      right: 10px;
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .dashboard-widget__resize {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      font-size: 16px;
      color: #909399;
      cursor: pointer;
      border-radius: 4px;
      transition: all 0.2s;

      &:hover {
        color: #409eff;
        background: #ecf5ff;
      }
    }

    .dashboard-widget__remove {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      color: #f56c6c;
      background: #fef0f0;
      border: 1px solid #fbc4c4;
      border-radius: 4px;
      transition: all 0.2s;

      &:hover {
        color: #fff;
        background: #f56c6c;
        border-color: #f56c6c;
      }
    }

    .widget-catalog-panel {
      position: sticky;
      top: 20px;
      padding: 14px;
    }

    .widget-catalog-panel__header {
      margin-bottom: 14px;

      h3 {
        margin: 0 0 2px;
        font-size: 15px;
        font-weight: 600;
      }

      p {
        margin: 0;
        font-size: 11px;
        color: var(--art-gray-500, #909399);
      }
    }

    .widget-catalog {
      max-height: calc(100vh - 200px);
      overflow-y: auto;
      margin: 0 -4px;
    }

    .widget-catalog__row {
      row-gap: 8px;
    }

    .widget-catalog__col {
      display: flex;
    }

    .widget-catalog__item {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 8px;
      width: 100%;
      padding: 10px;
      border: 1px solid var(--art-border-color, #e4e7ed);
      border-radius: 6px;
      cursor: default;
      background: #fff;
      transition:
        box-shadow 0.2s,
        border-color 0.2s,
        transform 0.15s;

      &:hover {
        border-color: var(--art-primary-color, #409eff);
        box-shadow: 0 2px 12px rgba(64, 158, 255, 0.12);
        transform: translateY(-1px);
      }
    }

    .widget-catalog__body {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .widget-catalog__header {
      display: flex;
      align-items: center;
      gap: 6px;

      h4 {
        margin: 0;
        font-size: 13px;
        font-weight: 600;
        line-height: 1.4;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .widget-catalog__source {
      flex-shrink: 0;
      font-size: 9px;
      padding: 0 5px;
      line-height: 15px;
      border-radius: 3px;
      white-space: nowrap;

      &--local {
        background: #ecf5ff;
        color: #409eff;
      }

      &--remote {
        background: #fdf6ec;
        color: #e6a23c;
      }
    }

    .widget-catalog__item {
      p {
        margin: 0;
        font-size: 11px;
        line-height: 1.4;
        color: var(--art-gray-500, #909399);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .widget-catalog__provider {
      font-size: 10px;
      color: var(--art-gray-400, #c0c4cc);
      line-height: 1.3;
    }

    .widget-catalog__add {
      width: 100%;
    }

    :deep(.sortable-ghost) {
      opacity: 0.4;
    }
  }

  @media screen and (max-width: 1080px) {
    .dashboard-console {
      .dashboard-layout {
        flex-direction: column;
      }

      .dashboard-layout__aside {
        width: 100% !important;
      }

      .widget-catalog-panel {
        position: static;
      }

      .widget-catalog {
        max-height: none;
      }
    }
  }
</style>
