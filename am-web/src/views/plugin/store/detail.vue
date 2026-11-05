<template>
  <ElMain class="store-detail">
    <section class="detail-hero">
      <div class="detail-app-icon">
        <img v-if="app.icon" :src="app.icon" :alt="app.title" />
        <ArtSvgIcon v-else :icon="getCategoryIcon(app.category)" />
      </div>
      <div class="detail-hero-main">
        <div class="detail-title-row">
          <h2>{{ app.title }}</h2>
          <ElTag v-if="app.is_paid" type="danger" effect="plain">
            {{
              app.purchased
                ? `付费已购 ${formatPrice(app.price, app.price_unit)}`
                : `付费 ${formatPrice(app.price, app.price_unit)}`
            }}
          </ElTag>
          <ElTag v-if="licenseStatusInfo" :type="licenseStatusInfo.type" effect="plain">
            {{ licenseStatusInfo.label }}
          </ElTag>
          <ElTag v-for="tag in app.tags" :key="tag" effect="plain" type="primary">{{ tag }}</ElTag>
        </div>
        <p>{{ app.description }}</p>
        <div class="detail-meta">
          <span>当前版本：v{{ app.version }}</span>
          <span v-if="app.installed">本地版本：v{{ app.local_version }}</span>
          <span>开发者：{{ app.author }}</span>
          <span>下载量：{{ app.download_count }}</span>
          <span v-if="app.is_paid"
            >授权类型：{{ app.license_type === 'subscription' ? '订阅制' : '永久' }}</span
          >
        </div>
      </div>
      <div class="detail-hero-actions">
        <ElButton
          v-if="!app.installed"
          type="primary"
          size="large"
          :loading="installing"
          v-auth="'system:store:install'"
          @click="handleInstall"
        >
          {{ app.is_paid && !app.purchased ? '前往购买' : '安装应用' }}
        </ElButton>
        <ElButton
          v-else-if="app.has_update"
          type="warning"
          size="large"
          :loading="updating"
          v-auth="'system:store:update'"
          @click="handleUpdate"
        >
          更新应用
        </ElButton>
      </div>
    </section>

    <ElRow :gutter="18" class="detail-content" align="top">
      <ElCol :xs="24" :lg="16">
        <section v-if="app.screenshots?.length" class="detail-card">
          <div class="detail-card-header">
            <h3>应用截图</h3>
            <p>预览应用核心功能界面</p>
          </div>
          <div class="screenshot-stage">
            <ElImage
              :src="app.screenshots[activeShot]"
              :alt="`截图${activeShot + 1}`"
              fit="contain"
              :preview-src-list="app.screenshots"
              :initial-index="activeShot"
              preview-teleported
            />
          </div>
          <div v-if="app.screenshots.length > 1" class="screenshot-thumbs">
            <img
              v-for="(shot, idx) in app.screenshots"
              :key="idx"
              :src="shot"
              :alt="`截图${idx + 1}`"
              :class="{ active: idx === activeShot }"
              @click="selectShot(idx, $event)"
            />
          </div>
        </section>

        <section class="detail-card">
          <ElTabs v-model="activeTab" class="detail-tabs">
            <ElTabPane label="应用描述" name="description" :lazy="false">
              <!-- 富文本详情（WangEditor）优先，DOMPurify 消毒后渲染；无详情时降级一句话简介 -->
              <div v-if="sanitizedDetail" class="rich-detail" v-html="sanitizedDetail"></div>
              <p v-else class="detail-description">{{ app.description }}</p>
            </ElTabPane>
            <ElTabPane label="版本历史" name="versions" :lazy="false">
              <div v-if="versionsLoading" class="version-loading">
                <ElIcon class="is-loading"><Loading /></ElIcon>
                <span>加载中...</span>
              </div>
              <div v-else-if="versions.length" class="version-timeline">
                <ElTimeline>
                  <ElTimelineItem
                    v-for="v in versions"
                    :key="v.version"
                    :timestamp="v.release_date || '未知时间'"
                    :type="getTimelineType(v)"
                    :hollow="!isCurrentVersion(v)"
                    :size="isCurrentVersion(v) ? 'large' : 'normal'"
                    class="version-item"
                  >
                    <div class="version-content">
                      <div class="version-header">
                        <span class="version-number">v{{ v.version }}</span>
                        <ElTag v-if="isCurrentVersion(v)" type="success" size="small">最新</ElTag>
                        <ElTag v-if="v.is_required" type="danger" size="small" effect="plain">必装</ElTag>
                        <ElTag v-if="!isVersionCompatible(v)" type="info" size="small" effect="plain">不兼容</ElTag>
                        <span v-if="v.support_system_version" class="support-version">
                          需要基座 v{{ v.support_system_version }}
                        </span>
                        <ElButton
                          v-if="!app.installed && isVersionCompatible(v)"
                          class="version-install-btn"
                          type="primary"
                          size="small"
                          @click="handleInstallVersion(v.version)"
                        >
                          安装此版本
                        </ElButton>
                      </div>
                      <div class="version-changelog" v-html="formatChangelog(v.changelog)"></div>
                    </div>
                  </ElTimelineItem>
                </ElTimeline>
              </div>
              <div v-else-if="app.changelog" class="version-fallback">
                <pre class="detail-changelog">{{ app.changelog }}</pre>
              </div>
              <div v-else class="version-empty">暂无版本记录</div>
            </ElTabPane>
          </ElTabs>
        </section>
      </ElCol>

      <ElCol :xs="24" :lg="8">
        <section class="detail-card">
          <div class="detail-card-header">
            <h3>应用信息</h3>
          </div>
          <div class="info-list">
            <div class="info-row">
              <span>应用分类</span>
              <strong>{{ categoryLabel }}</strong>
            </div>
            <div class="info-row">
              <span>应用标识</span>
              <strong>{{ app.name }}</strong>
            </div>
            <div class="info-row">
              <span>当前版本</span>
              <strong>v{{ app.version }}</strong>
            </div>
            <div class="info-row">
              <span>安装状态</span>
              <strong>
                <ElTag v-if="app.installed && !app.has_update" type="success" size="small"
                  >已安装</ElTag
                >
                <ElTag v-else-if="app.has_update" type="warning" size="small">可更新</ElTag>
                <ElTag v-else type="info" size="small">未安装</ElTag>
              </strong>
            </div>
            <div class="info-row">
              <span>下载量</span>
              <strong>{{ app.download_count }}</strong>
            </div>
            <div v-if="app.is_paid" class="info-row">
              <span>价格</span>
              <strong class="detail-price">{{
                app.purchased ? '已购买' : formatPrice(app.price, app.price_unit)
              }}</strong>
            </div>
          </div>
        </section>

        <section v-if="app.installed" class="detail-card">
          <div class="detail-card-header">
            <h3>本地信息</h3>
          </div>
          <div class="info-list">
            <div class="info-row">
              <span>本地版本</span>
              <strong>v{{ app.local_version }}</strong>
            </div>
            <div class="info-row">
              <span>插件ID</span>
              <strong>{{ app.plugin_id }}</strong>
            </div>
            <div v-if="app.is_paid" class="info-row">
              <span>授权状态</span>
              <strong>
                <ElTag v-if="licenseStatusInfo" :type="licenseStatusInfo.type" size="small">
                  {{ licenseStatusInfo.label }}
                </ElTag>
                <span v-else>-</span>
              </strong>
            </div>
          </div>
        </section>
      </ElCol>
    </ElRow>

    <!-- 应用升级确认弹窗（预检后由 storeUtils 单例驱动） -->
    <StoreUpgradeDialog />
  </ElMain>
</template>
<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import DOMPurify from 'dompurify'
  import { ElIcon, ElMessageBox } from 'element-plus'
  import { Loading } from '@element-plus/icons-vue'
  import storeApi, { type StoreApp, type StoreVersion } from '@/api/plugin/store'
  import {
    getCategoryIcon,
    formatPrice,
    confirmStoreUpgrade,
    presentCascadeResult
  } from './storeUtils'
  import StoreUpgradeDialog from './modules/store-upgrade-dialog.vue'

  interface Props {
    app: StoreApp
    categoryName?: string
  }

  const props = withDefaults(defineProps<Props>(), {
    categoryName: '全部应用'
  })

  const emit = defineEmits<{
    installed: []
    updated: []
    purchase: [info: { message?: string; price?: number; buy_url?: string }]
    needBind: []
  }>()

  const installing = ref(false)
  const updating = ref(false)
  const versions = ref<StoreVersion[]>([])
  const versionsLoading = ref(false)
  const systemVersion = ref<string>('')
  const activeTab = ref('description')
  const activeShot = ref(0)

  const categoryLabel = computed(() => props.categoryName)

  /** 判断是否为最新版本 */
  const isCurrentVersion = (v: StoreVersion): boolean => {
    return v.version === props.app.version
  }

  /** 时间轴节点类型 - 统一主色 */
  const getTimelineType = (_v: StoreVersion): 'primary' => {
    return 'primary'
  }

  /** 版本兼容性检查：support_system_version 为空则兼容，否则需当前基座版本 >= 支持版本 */
  const isVersionCompatible = (v: StoreVersion): boolean => {
    if (!v.support_system_version) return true
    if (!systemVersion.value) return true // 未知基座版本时默认兼容
    return compareVersion(systemVersion.value, v.support_system_version) >= 0
  }

  /** 语义化版本比较 */
  const compareVersion = (v1: string, v2: string): number => {
    const parts1 = v1.split('.').map(Number)
    const parts2 = v2.split('.').map(Number)
    const len = Math.max(parts1.length, parts2.length)
    for (let i = 0; i < len; i++) {
      const n1 = parts1[i] || 0
      const n2 = parts2[i] || 0
      if (n1 > n2) return 1
      if (n1 < n2) return -1
    }
    return 0
  }

  /** 安装指定版本 */
  const handleInstallVersion = async (version: string) => {
    // 安装前检查市场账号绑定状态
    const bind = await storeApi.marketStatus()
    if (!bind?.bound) {
      emit('needBind')
      return
    }

    installing.value = true
    try {
      const res: any = await storeApi.install(props.app.id || props.app.name, version)
      if (res?.need_purchase) {
        emit('purchase', res)
        return
      }
      ElMessageBox.alert('应用安装成功！请前往【管理应用中心】启用该插件后即可使用。', '安装成功', {
        type: 'success',
        confirmButtonText: '我知道了'
      })
      emit('installed')
    } catch {
      // 拦截器已处理错误提示
    } finally {
      installing.value = false
    }
  }

  // 富文本详情消毒渲染：新内容为 WangEditor HTML；历史纯文本草稿保留换行后同样走消毒流程
  const sanitizedDetail = computed(() => {
    const raw = (props.app?.detail || '').trim()
    if (!raw) return ''
    const isHtml = /<[a-z][\s\S]*>/i.test(raw)
    const html = isHtml ? raw : raw.replace(/\r?\n/g, '<br>')
    return DOMPurify.sanitize(html, { USE_PROFILES: { html: true } })
  })

  /** 格式化版本日志为消毒 HTML */
  const formatChangelog = (text: string): string => {
    const raw = (text || '').trim()
    if (!raw) return ''
    return DOMPurify.sanitize(raw.replace(/\r?\n/g, '<br>'), { USE_PROFILES: { html: true } })
  }

  /** 加载版本历史与基座版本 */
  const loadVersions = async () => {
    versionsLoading.value = true
    versions.value = []
    try {
      // 并行请求版本列表与系统版本
      const [versionsData, systemData] = await Promise.all([
        storeApi.versions(props.app.id || props.app.name).catch(() => []),
        storeApi.systemUpdate().catch(() => null)
      ])
      versions.value = Array.isArray(versionsData) ? versionsData : []
      if (systemData && typeof systemData === 'object' && 'current_version' in systemData) {
        systemVersion.value = (systemData as any).current_version || ''
      }
    } finally {
      versionsLoading.value = false
    }
  }

  // 应用切换时重新加载版本历史（组件复用，onMounted 不会重复触发）
  watch(
    () => props.app.id,
    () => {
      activeShot.value = 0
      loadVersions()
    },
    { immediate: true }
  )

  /** 切换截图，并让被点的缩略图滚进可视区 */
  const selectShot = (idx: number, e: MouseEvent) => {
    activeShot.value = idx
    ;(e.currentTarget as HTMLElement).scrollIntoView({ behavior: 'smooth', inline: 'nearest', block: 'nearest' })
  }

  /** 授权状态展示（付费应用） */
  const licenseStatusInfo = computed(() => {
    if (!props.app.is_paid || !props.app.installed) return null
    const map: Record<number, { label: string; type: 'success' | 'warning' | 'danger' | 'info' }> =
      {
        1: { label: '授权有效', type: 'success' },
        0: { label: '授权过期', type: 'danger' },
        [-1]: { label: '已吊销', type: 'danger' },
        [-2]: { label: '校验失败', type: 'warning' }
      }
    return map[props.app.license_status as number] || { label: '未知', type: 'info' }
  })

  const handleInstall = async () => {
    // 安装前检查市场账号绑定状态
    if (!props.app.installed) {
      const res = await storeApi.marketStatus()
      if (!res?.bound) {
        emit('needBind')
        return
      }
    }

    // 二次确认
    try {
      await ElMessageBox.confirm(`确定要安装「${props.app.title}」吗？`, '安装确认', {
        type: 'info'
      })
    } catch {
      return
    }

    installing.value = true
    try {
      const res: any = await storeApi.install(props.app.id)
      if (res?.need_purchase) {
        emit('purchase', res)
        return
      }
      // 安装成功提示：应用市场不直接展示打开按钮，引导去管理应用中心启用
      ElMessageBox.alert('应用安装成功！请前往【管理应用中心】启用该插件后即可使用。', '安装成功', {
        type: 'success',
        confirmButtonText: '我知道了'
      })
      emit('installed')
    } catch {
      // 拦截器已处理错误提示
    } finally {
      installing.value = false
    }
  }

  const handleUpdate = async () => {
    // 更新前检查市场账号绑定状态
    const bind = await storeApi.marketStatus()
    if (!bind?.bound) {
      emit('needBind')
      return
    }
    updating.value = true
    try {
      const decision = await confirmStoreUpgrade(props.app.id)
      if (decision.action === 'purchase') {
        emit('purchase', decision.buy)
        return
      }
      if (decision.action === 'none') {
        emit('updated')
        return
      }

      const res: any = await storeApi.update(props.app.id, decision.cascade)

      if (decision.cascade) {
        const shown = await presentCascadeResult(res)
        if (shown.needPurchase) emit('purchase', shown.needPurchase)
        else if (res?.ok) emit('updated')
        return
      }

      // 非级联：res 为 enriched plugin（拦截器已提示“更新成功”）或 need_purchase
      if (res?.need_purchase) {
        emit('purchase', res)
        return
      }
      emit('updated')
    } catch {
      // 级联确认取消 / 其余错误：拦截器或 confirm 已处理
    } finally {
      updating.value = false
    }
  }
</script>
<style scoped lang="scss">
  .store-detail {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 54px 42px;
    color: var(--art-gray-900);
    background: var(--default-box-color);
  }

  .detail-hero {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    padding: 22px;
    margin-bottom: 18px;
    background: var(--default-box-color);
    border: 1px solid var(--default-border);
    border-radius: 14px;
  }

  .detail-app-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    overflow: hidden;
    font-size: 38px;
    color: var(--el-color-white);
    border-radius: 16px;

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  }

  .detail-hero-main {
    min-width: 0;

    p {
      margin: 8px 0 12px;
      color: var(--art-gray-600);
    }
  }

  .detail-title-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;

    h2 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
    }
  }

  .detail-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 13px;
    color: var(--art-gray-500);
  }

  .detail-hero-actions {
    display: flex;
    gap: 8px;
  }

  .detail-content {
    row-gap: 18px;

    :deep(.el-col) {
      box-sizing: border-box;
    }
  }

  .detail-card {
    padding: 18px;
    margin-bottom: 18px;
    background: var(--default-box-color);
    border: 1px solid var(--default-border);
    border-radius: 14px;
  }

  .detail-tabs {
    width: 100%;

    :deep(.el-tabs__header) {
      margin-bottom: 16px;
    }

    :deep(.el-tabs__nav-wrap::after) {
      height: 1px;
    }

    :deep(.el-tabs__item) {
      font-size: 15px;
      font-weight: 500;
    }

    :deep(.el-tabs__content) {
      width: 100%;
      min-height: 200px;
      overflow: hidden;
      transition: none !important;
    }

    :deep(.el-tab-pane) {
      width: 100%;
      box-sizing: border-box;
      transition: none !important;
    }
  }

  .detail-card-header {
    margin-bottom: 16px;

    h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
    }

    p {
      margin: 6px 0 0;
      color: var(--art-gray-500);
    }
  }

  .screenshot-stage {
    height: 420px;
    overflow: hidden;
    background: var(--default-box-color);
    border: 1px dashed var(--default-border);
    border-radius: 10px;

    :deep(.el-image) {
      width: 100%;
      height: 100%;
    }
  }

  .screenshot-thumbs {
    display: flex;
    gap: 10px;
    margin-top: 12px;
    padding: 2px;
    overflow-x: auto;

    img {
      flex: 0 0 auto;
      width: 112px;
      height: 70px;
      object-fit: cover;
      cursor: pointer;
      border: 2px solid transparent;
      border-radius: 8px;
      opacity: 0.72;
      transition:
        opacity 0.2s,
        border-color 0.2s;

      &:hover {
        opacity: 1;
      }

      &.active {
        border-color: var(--el-color-primary);
        opacity: 1;
      }
    }
  }

  .info-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .info-row {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--default-border);

    &:last-child {
      padding-bottom: 0;
      border-bottom: 0;
    }

    span {
      margin: 0;
      color: var(--art-gray-500);
    }
  }

  .detail-price {
    color: var(--el-color-danger);
  }

  .detail-description {
    width: 100%;
    margin: 0;
    color: var(--art-gray-600);
    line-height: 1.8;
  }

  .rich-detail {
    width: 100%;
    font-size: 14px;
    line-height: 1.9;
    color: var(--art-gray-600);
    word-break: break-word;

    :deep(p) {
      margin: 0 0 10px;
    }

    :deep(h1),
    :deep(h2),
    :deep(h3),
    :deep(h4) {
      margin: 16px 0 10px;
      font-size: 16px;
      line-height: 1.5;
      color: var(--art-gray-900);
    }

    :deep(img),
    :deep(video) {
      max-width: 100%;
      border-radius: 8px;
    }

    :deep(ul),
    :deep(ol) {
      padding-left: 22px;
      margin: 0 0 10px;
    }

    :deep(table) {
      width: 100%;
      margin: 0 0 12px;
      border-collapse: collapse;
    }

    :deep(th),
    :deep(td) {
      padding: 6px 10px;
      border: 1px solid var(--default-border);
    }

    :deep(blockquote) {
      padding: 8px 14px;
      margin: 0 0 12px;
      color: var(--art-gray-500);
      background: var(--art-active-color);
      border-left: 3px solid var(--el-color-primary);
      border-radius: 4px;
    }

    :deep(pre) {
      padding: 12px 14px;
      margin: 0 0 12px;
      overflow: auto;
      font-size: 13px;
      line-height: 1.7;
      white-space: pre-wrap;
      word-break: break-all;
      background: var(--art-active-color);
      border-radius: 8px;
    }

    :deep(a) {
      color: var(--el-color-primary);
      text-decoration: none;
    }
  }

  .detail-changelog {
    margin: 0;
    padding: 12px;
    font-family: inherit;
    font-size: 13px;
    color: var(--art-gray-600);
    line-height: 1.8;
    white-space: pre-wrap;
    background: var(--art-active-color);
    border-radius: 8px;
  }

  // 版本历史时间轴
  .version-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 24px;
    color: var(--art-gray-500);
  }

  .version-empty {
    padding: 24px;
    text-align: center;
    color: var(--art-gray-400);
  }

  .version-fallback {
    margin-top: 8px;
  }

  .version-timeline {
    width: 100%;

    :deep(.el-timeline) {
      margin-top: 8px;
      padding-left: 4px;
    }

    :deep(.el-timeline-item__wrapper) {
      padding-left: 20px;
    }

    :deep(.el-timeline-item__timestamp) {
      font-size: 12px;
      color: var(--art-gray-400);
    }
  }

  .version-item {
    cursor: default;
  }

  .version-content {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    border-radius: 10px;
    background: var(--art-active-color);
  }

  .version-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;

    .version-number {
      font-size: 14px;
      font-weight: 600;
      color: var(--art-gray-800);
    }

    .version-install-btn {
      margin-left: auto;
    }

    .support-version {
      font-size: 12px;
      color: var(--art-gray-400);
    }
  }

  .version-changelog {
    margin-top: 10px;
    padding-top: 10px;
    font-size: 13px;
    line-height: 1.8;
    color: var(--art-gray-600);
    border-top: 1px dashed var(--default-border);
    word-break: break-word;
  }

  @media (max-width: 1280px) {
    .store-detail {
      padding: 24px;
    }
  }

  @media (max-width: 760px) {
    .detail-hero {
      grid-template-columns: 1fr;
    }

    .screenshot-stage {
      height: 260px;
    }
  }
</style>
