<template>
  <div class="plugin-page" v-loading="loading">
    <template v-if="!detailVisible">
      <div class="plugin-header">
        <div>
          <h2 class="plugin-title">应用</h2>
          <p class="plugin-subtitle">统一管理后台插件应用，支持启用、打开和维护。</p>
        </div>
        <ElSpace wrap>
          <ElButton type="primary" plain @click="handleScan" v-auth="'system:plugin:scan'">
            扫描插件
          </ElButton>
          <ElButton
            type="warning"
            plain
            @click="packageVisible = true"
            v-auth="'system:plugin:installPackage'"
          >
            插件包管理
          </ElButton>
          <ElButton
            type="success"
            plain
            v-auth="'system:plugin:save'"
            @click="router.push('/plugin/store')"
          >
            应用插件
          </ElButton>
        </ElSpace>
      </div>

      <PluginSearch
        v-model="searchForm"
        class="plugin-search"
        @search="handleSearch"
        @reset="handleResetSearch"
      />

      <section class="plugin-section">
        <div class="section-title">基础</div>
        <div v-if="basicPlugins.length" class="plugin-grid">
          <PluginCard
            v-for="plugin in basicPlugins"
            :key="plugin.id"
            :plugin="plugin"
            :editable="isCustomPlugin(plugin)"
            :loading="isPluginActionLoading(plugin)"
            @detail="showDetail(plugin)"
            @open="handlePluginDoubleClick(plugin)"
            @edit="showDialog('edit', plugin)"
            @action="(action) => handleAction(plugin, action)"
            @backup="handleBackup(plugin)"
            @uninstall="openUninstallDialog(plugin)"
            @requirement-invalid="showRequirementMessage(plugin)"
          />
        </div>
        <ElEmpty v-else description="暂无应用" />
      </section>

      <section class="plugin-section third-party-section">
        <div class="section-header">
          <div class="section-title">自建应用</div>
          <ElButton link type="primary" @click="showDialog('add')" v-auth="'system:plugin:save'">
            添加应用
          </ElButton>
        </div>
        <div class="plugin-grid">
          <PluginCard
            v-for="plugin in customPlugins"
            :key="plugin.id"
            :plugin="plugin"
            :loading="isPluginActionLoading(plugin)"
            custom
            editable
            @detail="showDetail(plugin)"
            @open="handlePluginDoubleClick(plugin)"
            @edit="showDialog('edit', plugin)"
            @action="(action) => handleAction(plugin, action)"
            @backup="handleBackup(plugin)"
            @uninstall="openUninstallDialog(plugin)"
            @requirement-invalid="showRequirementMessage(plugin)"
          />
          <div
            class="plugin-card add-card"
            @click="showDialog('add')"
            v-auth="'system:plugin:save'"
          >
            <div class="plugin-icon add-icon">
              <ArtSvgIcon icon="ri:add-line" />
            </div>
            <div class="plugin-info">
              <div class="plugin-name add-text">添加应用</div>
            </div>
          </div>
        </div>
      </section>
    </template>

    <PluginDetailPanel
      v-else
      :detail="detailData"
      :switch-loading="detailSwitchLoading"
      @back="hideDetail"
      @status-change="handleDetailStatusChange"
      @edit="handleDetailEdit"
      @saved="handleDetailSaved"
      @scaffold="handleDetailScaffold"
      @package="handleDetailPackage"
      @composer="handleDetailComposer"
    />

    <PluginDialog
      v-model="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      @success="handleSubmit"
    />

    <PluginUninstallDialog
      v-model="uninstallVisible"
      :plugin="uninstallTarget"
      @success="handleUninstallSuccess"
    />

    <PluginPackageDialog v-model="packageVisible" @success="refreshData" />

    <PluginComposerDialog
      v-model="composerVisible"
      :plugin="composerTarget"
      @success="handleComposerSuccess"
    />

    <PluginPackageFormDialog
      v-model="packageFormVisible"
      :plugin="packageTarget"
      @success="handlePackageSuccess"
    />

    <ElDialog
      v-model="restartVisible"
      :title="restartTitle"
      width="420px"
      align-center
      append-to-body
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      :show-close="false"
    >
      <div class="restart-reload">
        <ArtSvgIcon icon="ri:checkbox-circle-fill" class="restart-reload__icon" />
        <p class="restart-reload__title">{{ restartHeadline }}</p>
        <p class="restart-reload__desc">
          {{ restartCountdown > 0 ? `${restartCountdown} 秒后自动重载页面…` : '正在重载页面…' }}
        </p>
      </div>
      <template #footer>
        <ElButton type="primary" :disabled="restartCountdown > 0" @click="doReloadPage">
          重载页面
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useTable } from '@/hooks/core/useTable'
  import { tableOperation } from '@/mixins/tableOperation'
  import pluginApi from '@/api/plugin/app'
  import {
    canEnablePlugin,
    getRequirementMessage,
    hasPluginEntry,
    isCustomPlugin,
    type PluginRecord
  } from '@/utils/plugin'
  import {
    prefetchPluginAppsIdle,
    invalidatePluginDetail,
    unmarkPluginPrefetched
  } from '@/utils/pluginMicroApp'
  import PluginDialog from './modules/plugin-dialog.vue'
  import PluginDetailPanel from './modules/plugin-detail-dialog.vue'
  import PluginPackageDialog from './modules/plugin-package-dialog.vue'
  import PluginCard from './modules/plugin-card.vue'
  import PluginSearch from './modules/plugin-search.vue'
  import PluginUninstallDialog from './modules/plugin-uninstall-dialog.vue'
  import PluginComposerDialog from './modules/plugin-composer-dialog.vue'
  import PluginPackageFormDialog from './modules/plugin-package-form-dialog.vue'

  defineOptions({ name: 'Plugin' })

  const { dialogType, dialogVisible, dialogData, showDialog } = tableOperation()

  const router = useRouter()
  const detailVisible = ref(false)
  const packageVisible = ref(false)
  const detailSwitchLoading = ref(false)
  const detailData = ref<Record<string, any>>({})
  const uninstallVisible = ref(false)
  const uninstallTarget = ref<PluginRecord>()
  const composerVisible = ref(false)
  const composerTarget = ref<PluginRecord>()
  const packageFormVisible = ref(false)
  const packageTarget = ref<PluginRecord>()

  // 启停成功后的「重启服务 + 倒计时重载」提示：
  // 倒计时到 0 自动 window.location.reload()；按钮「重载页面」在倒计时结束前置灰不可点，
  // 若到时未能自动重载（如被浏览器拦截），倒计时结束后用户可手动点击重载。
  // 禁用同样需要重载：入口菜单已随插件停用，重载后基座侧栏才收起该入口。
  const restartVisible = ref(false)
  const restartAction = ref<'enable' | 'disable'>('enable')
  const restartCountdown = ref(5)
  let restartTimer: ReturnType<typeof setInterval> | null = null

  const restartTitle = computed(() => (restartAction.value === 'enable' ? '启用成功' : '禁用成功'))
  const restartHeadline = computed(() =>
    restartAction.value === 'enable' ? '启动成功，正在重启服务' : '禁用成功，正在重启服务'
  )

  const clearRestartTimer = () => {
    if (restartTimer) {
      clearInterval(restartTimer)
      restartTimer = null
    }
  }

  const doReloadPage = () => {
    clearRestartTimer()
    window.location.reload()
  }

  const showRestartReloadDialog = (action: 'enable' | 'disable' = 'enable') => {
    clearRestartTimer()
    restartAction.value = action
    restartCountdown.value = 5
    restartVisible.value = true
    restartTimer = setInterval(() => {
      restartCountdown.value -= 1
      if (restartCountdown.value <= 0) doReloadPage()
    }, 1000)
  }

  onUnmounted(clearRestartTimer)

  const searchForm = ref({
    keywords: '',
    status: '',
    is_custom: '',
    is_dev: ''
  })
  const actionLoadingMap = ref<Record<string, boolean>>({})

  const {
    data,
    loading,
    searchParams,
    getData,
    resetSearchParams,
    refreshData,
    refreshCreate,
    refreshUpdate
  } = useTable<typeof pluginApi.list>({
    performance: {
      debounceTime: 500
    },
    core: {
      apiFn: pluginApi.list,
      apiParams: {
        ...searchForm.value
      }
    }
  })

  const basicPlugins = computed<PluginRecord[]>(() =>
    (data.value as PluginRecord[]).filter((plugin) => !isCustomPlugin(plugin))
  )

  // 列表就绪后，浏览器空闲时预取各子应用静态资源，显著加快后续打开速度
  watch(
    data,
    (list) => {
      if (list?.length) prefetchPluginAppsIdle(list as PluginRecord[])
    },
    { immediate: true }
  )
  const customPlugins = computed<PluginRecord[]>(() =>
    (data.value as PluginRecord[]).filter((plugin) => isCustomPlugin(plugin))
  )

  const handleSearch = (params: Record<string, any>) => {
    Object.assign(searchParams, params)
    getData()
  }

  const handleResetSearch = () => {
    resetSearchParams()
  }

  const handleSubmit = async () => {
    if (dialogType.value === 'add') {
      refreshCreate()
    } else {
      refreshUpdate()
      if (detailVisible.value && detailData.value.id) {
        detailData.value = await pluginApi.read(detailData.value.id)
      }
    }
  }

  const handleScan = async () => {
    await pluginApi.scan()
    refreshData()
  }

  const showRequirementMessage = (plugin: PluginRecord) => {
    ElMessage.warning(getRequirementMessage(plugin))
  }

  const getActionKey = (plugin: PluginRecord, action: string) =>
    `${plugin.id || plugin.name}:${action}`

  const isPluginActionLoading = (plugin: PluginRecord, action?: string) => {
    if (action) {
      return Boolean(actionLoadingMap.value[getActionKey(plugin, action)])
    }
    const prefix = `${plugin.id || plugin.name}:`
    return Object.entries(actionLoadingMap.value).some(
      ([key, loading]) => key.startsWith(prefix) && loading
    )
  }

  const actionConfirmMap: Partial<Record<'install' | 'enable' | 'disable' | 'reload', string>> = {
    disable: '确定要禁用该插件吗？禁用后用户将无法打开该插件。',
    reload: '确定要重载该插件配置吗？'
  }

  const handleAction = async (
    row: PluginRecord,
    action: 'install' | 'enable' | 'disable' | 'reload' | 'composer'
  ) => {
    // 安装依赖走检测优先的弹窗，不直接调接口
    if (action === 'composer') {
      composerTarget.value = row
      composerVisible.value = true
      return
    }

    const key = getActionKey(row, action)
    if (actionLoadingMap.value[key]) return

    const confirmMessage = actionConfirmMap[action]
    if (confirmMessage) {
      try {
        await ElMessageBox.confirm(confirmMessage, '操作确认', { type: 'warning' })
      } catch {
        return
      }
    }

    actionLoadingMap.value[key] = true
    try {
      await pluginApi[action]({ id: row.id, name: row.name })
      // 状态/入口可能变化：失效详情缓存并允许重新预取，刷新列表后自动预热最新资源
      invalidatePluginDetail(String(row.name))
      unmarkPluginPrefetched(String(row.name))
      await refreshData()
      if (detailVisible.value && detailData.value.id === row.id) {
        detailData.value = await pluginApi.read(row.id)
      }
      // 启停成功：提示服务重启并倒计时重载
      if (action === 'enable' || action === 'disable') showRestartReloadDialog(action)
    } finally {
      actionLoadingMap.value[key] = false
    }
  }

  const handleBackup = async (plugin: PluginRecord) => {
    const key = getActionKey(plugin, 'backup')
    if (actionLoadingMap.value[key]) return
    try {
      await ElMessageBox.confirm(`确定要备份「${plugin.title || plugin.name}」吗？`, '备份插件', {
        type: 'warning'
      })
    } catch {
      return
    }

    actionLoadingMap.value[key] = true
    try {
      await pluginApi.backup({ id: plugin.id, name: plugin.name })
    } finally {
      actionLoadingMap.value[key] = false
    }
  }

  const openUninstallDialog = async (plugin: PluginRecord) => {
    if (Number(plugin.status) === 1) {
      ElMessage.warning('插件正在启用中，请先禁用后再卸载')
      return
    }
    if (![0, 2].includes(Number(plugin.status))) {
      ElMessage.warning('当前状态不允许卸载')
      return
    }
    uninstallTarget.value = plugin
    uninstallVisible.value = true
  }

  const handleUninstallSuccess = async (plugin: PluginRecord) => {
    invalidatePluginDetail(String(plugin.name))
    unmarkPluginPrefetched(String(plugin.name))
    if (detailVisible.value && detailData.value.id === plugin.id) {
      detailData.value = await pluginApi.read(plugin.id)
    }
    refreshData()
  }

  const handleScaffold = async (row: Record<string, any>) => {
    await pluginApi.scaffold({ id: row.id, name: row.name })
    refreshData()
  }

  const handleDetailStatusChange = async (enabled: boolean) => {
    if (enabled && Number(detailData.value.status) !== 0) {
      ElMessage.warning(
        Number(detailData.value.status) === 2 ? '请先安装插件' : '当前状态不允许启用'
      )
      return
    }
    if (enabled && !canEnablePlugin(detailData.value)) {
      ElMessage.warning(getRequirementMessage(detailData.value))
      return
    }
    if (!enabled && Number(detailData.value.status) !== 1) {
      ElMessage.warning('当前状态不允许禁用')
      return
    }

    detailSwitchLoading.value = true
    try {
      const action = enabled ? 'enable' : 'disable'
      await pluginApi[action]({ id: detailData.value.id, name: detailData.value.name })
      detailData.value = await pluginApi.read(detailData.value.id)
      refreshData()
      // 启停成功：提示服务重启并倒计时重载
      showRestartReloadDialog(enabled ? 'enable' : 'disable')
    } finally {
      detailSwitchLoading.value = false
    }
  }

  const handleOpenPlugin = (row: Record<string, any>) => {
    router.push({
      name: 'PluginApp',
      params: { name: row.name },
      query: { title: row.title || row.name }
    })
  }

  const handlePluginDoubleClick = (plugin: PluginRecord) => {
    if (Number(plugin.status) !== 1) {
      ElMessage.warning('插件未启用，无法打开')
      return
    }
    if (!hasPluginEntry(plugin)) {
      ElMessage.warning('插件未配置应用入口')
      return
    }
    handleOpenPlugin(plugin)
  }

  const showDetail = (row: Record<string, any>) => {
    detailData.value = row
    detailVisible.value = true
  }

  const hideDetail = () => {
    detailVisible.value = false
    detailData.value = {}
  }

  const handleDetailEdit = () => {
    showDialog('edit', detailData.value)
  }

  const handleDetailSaved = async () => {
    if (detailData.value.id) {
      detailData.value = await pluginApi.read(detailData.value.id)
    }
    refreshData()
  }

  const handleDetailScaffold = async () => {
    try {
      await ElMessageBox.confirm(
        '确定要重新生成应用代码吗？系统只会补齐缺失的模板文件，不会覆盖已有代码。',
        '重新生成代码',
        { type: 'warning' }
      )
    } catch {
      return
    }
    handleScaffold(detailData.value)
  }

  // 生成安装包前先确认发布配置：弹窗内查询应用信息回显，表单提交后由后端生成并下载 zip
  const handleDetailPackage = () => {
    packageTarget.value = detailData.value as PluginRecord
    packageFormVisible.value = true
  }

  // 打包会写回版本/依赖/Bridge 权限，停留详情页时同步刷新展示；
  // Bridge 权限直接影响宿主下发给子应用的能力，需让微应用详情缓存失效。
  const handlePackageSuccess = async (plugin: PluginRecord) => {
    invalidatePluginDetail(String(plugin.name))
    await refreshData()
    if (detailVisible.value && detailData.value.id) {
      detailData.value = await pluginApi.read(detailData.value.id)
    }
  }

  // 详情页的依赖展示区点击“检测 / 安装”：以当前详情为目标弹出 Composer 弹窗
  const handleDetailComposer = () => {
    composerTarget.value = detailData.value
    composerVisible.value = true
  }

  // 安装完成后刷新列表（角标依新状态隐显）；若正停留详情页，同步刷新详情依赖状态
  const handleComposerSuccess = async () => {
    await refreshData()
    if (detailVisible.value && detailData.value.id) {
      detailData.value = await pluginApi.read(detailData.value.id)
    }
  }
</script>

<style scoped lang="scss">
  .restart-reload {
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
    padding: 8px 0 4px;
    text-align: center;
  }

  .restart-reload__icon {
    font-size: 44px;
    color: var(--el-color-success);
  }

  .restart-reload__title {
    margin: 6px 0 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--art-text-gray-900);
  }

  .restart-reload__desc {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .plugin-page {
    // max-width: 1040px;
    min-height: 100%;
    padding: 4px 0 40px;
    margin: 0 auto;
  }

  .plugin-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .plugin-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
    color: var(--art-text-gray-900);
  }

  .plugin-subtitle {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .plugin-alert {
    margin: 22px 0 18px;
  }

  .plugin-search {
    margin: 18px 0 8px;
  }

  .plugin-section {
    padding: 10px 0 24px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .section-title {
    margin-bottom: 14px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .plugin-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    overflow: visible;
  }

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

      .plugin-more {
        opacity: 1;
      }
    }

    &.is-disabled {
      color: var(--el-text-color-secondary);

      .plugin-icon {
        opacity: 0.55;
      }
    }
    .plugin-dropdown {
      position: absolute;
      top: 4px;
      right: 4px;
      z-index: 2;
      display: inline-flex;
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

  .plugin-name {
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

  .third-party-section {
    border-bottom: 0;
  }

  .add-card {
    color: var(--el-color-primary);
  }

  .add-icon {
    color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }

  .add-text {
    color: var(--el-color-primary);
  }

  @media (max-width: 1200px) {
    .plugin-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  @media (max-width: 900px) {
    .plugin-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 640px) {
    .plugin-header {
      flex-direction: column;
      gap: 12px;
    }

    .plugin-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
