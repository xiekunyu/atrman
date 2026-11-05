<template>
  <ElContainer class="store-page">
    <ElHeader class="store-header">
      <ElButton v-if="selectedApp" class="store-back" @click="selectedApp = undefined">
        <ArtSvgIcon icon="ri:arrow-left-line" />
      </ElButton>
      <h2>{{ selectedApp ? '应用详情' : '应用商店' }}</h2>
      <div class="store-header-right">
        <template v-if="market.bound">
          <ElTooltip :content="`实例ID：${market.instance_id}`" placement="bottom">
            <span class="store-market-account">
              <ArtSvgIcon icon="ri:user-smile-line" />
              {{ market.nickname || market.username }}
            </span>
          </ElTooltip>
          <ElButton link type="primary" @click="handleUnbind">解绑</ElButton>
        </template>
        <ElButton v-else type="primary" plain size="small" @click="bindVisible = true">
          <ArtSvgIcon icon="ri:link" />
          绑定市场账号
        </ElButton>
      </div>
    </ElHeader>

    <StoreDetail
      v-if="selectedApp"
      :app="selectedApp"
      :category-name="activeCategoryLabel"
      @installed="handleInstalled"
      @updated="handleInstalled"
      @purchase="showBuyDialog"
      @need-bind="bindVisible = true"
    />

    <ElContainer v-else class="store-body">
      <ElAside class="store-sidebar" width="198px">
        <div class="store-nav-top">
          <button
            v-for="item in topNav"
            :key="item.key"
            class="store-nav-item store-nav-item--top"
            :class="{ 'is-active': activeTop === item.key }"
            type="button"
            @click="handleTopNavChange(item.key)"
          >
            <ArtSvgIcon :icon="item.icon" />
            <span>{{ item.label }}</span>
          </button>
        </div>

        <div class="store-sidebar-divider" />

        <div class="store-category-list">
          <button
            v-for="category in categories"
            :key="category.key"
            class="store-nav-item"
            :class="{ 'is-active': activeTop !== 'installed' && activeCategory === category.key }"
            type="button"
            @click="handleCategoryChange(category.key)"
          >
            {{ category.label }}
          </button>
        </div>
      </ElAside>

      <ElMain class="store-content">
        <div class="store-search-wrap">
          <ElInput
            v-model="keyword"
            size="large"
            placeholder="搜索更多应用"
            clearable
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix>
              <ArtSvgIcon icon="ri:search-line" class="store-search-icon" />
            </template>
          </ElInput>
        </div>

        <section class="store-section">
          <h3>{{
            activeTop === 'installed'
              ? '已安装应用'
              : activeTop === 'recommend'
                ? '推荐应用'
                : activeCategoryLabel
          }}</h3>

          <div v-loading="loading" class="store-app-list">
            <ElEmpty v-if="!loading && apps.length === 0" description="暂无应用" />

            <ElRow v-else class="store-app-grid" :gutter="16">
              <ElCol
                v-for="app in apps"
                :key="app.id"
                :xs="24"
                :sm="12"
                :md="12"
                :lg="8"
                :xl="6"
                class="store-app-col"
              >
                <article class="store-app-card" @click="openDetail(app)">
                  <div class="store-app-icon">
                    <img v-if="app.icon" :src="app.icon" :alt="app.title" />
                    <ArtSvgIcon v-else :icon="getCategoryIcon(app.category)" />
                  </div>
                  <div class="store-app-info">
                    <h4>
                      <span class="store-app-title">{{ app.title }}</span>
                      <ElTag
                        v-if="app.is_paid"
                        class="store-paid-tag"
                        size="small"
                        type="danger"
                        effect="plain"
                      >
                        {{ app.purchased ? '已购' : formatPrice(app.price, app.price_unit) }}
                      </ElTag>
                    </h4>
                    <p>{{ app.description }}</p>
                  </div>
                  <div class="store-app-action">
                    <ElTag
                      v-if="app.installed && app.license_status === -1"
                      type="danger"
                      size="small"
                      >已吊销</ElTag
                    >
                    <ElTag
                      v-else-if="app.installed && app.license_status === 0"
                      type="danger"
                      size="small"
                      >授权过期</ElTag
                    >
                    <ElTag v-else-if="app.installed && !app.has_update" type="success" size="small"
                      >已安装</ElTag
                    >
                    <ElTag v-else-if="app.has_update" type="warning" size="small">可更新</ElTag>
                    <ElButton
                      v-if="!app.installed"
                      link
                      type="primary"
                      :loading="installingId === app.id"
                      v-auth="'system:store:install'"
                      @click.stop="handleInstall(app)"
                    >
                      {{ app.is_paid && !app.purchased ? '购买' : '安装' }}
                    </ElButton>
                    <ElButton
                      v-else-if="app.has_update"
                      link
                      type="warning"
                      :loading="updatingId === app.id"
                      v-auth="'system:store:update'"
                      @click.stop="handleUpdate(app)"
                    >
                      更新
                    </ElButton>
                  </div>
                </article>
              </ElCol>
            </ElRow>
          </div>

          <!-- 分页 -->
          <div v-if="total > 0" class="store-pagination">
            <ElPagination
              v-model:current-page="currentPage"
              v-model:page-size="pageSize"
              :total="total"
              :page-sizes="[12, 20, 40, 60]"
              layout="total, sizes, prev, pager, next, jumper"
              small
              @current-change="handlePageChange"
              @size-change="handleSizeChange"
            />
          </div>
        </section>
      </ElMain>
    </ElContainer>

    <!-- 绑定市场账号弹窗 -->
    <ElDialog v-model="bindVisible" title="绑定应用插件账号" width="420px">
      <ElAlert
        class="store-bind-tip"
        type="info"
        :closable="false"
        title="绑定市场账号后才能下载应用；付费应用需使用已购买的账号。"
      />
      <ElForm :model="bindForm" label-width="80px" @submit.prevent>
        <ElFormItem label="账号">
          <ElInput v-model="bindForm.username" placeholder="请输入市场账号" clearable />
        </ElFormItem>
        <ElFormItem label="密码">
          <ElInput
            v-model="bindForm.password"
            type="password"
            placeholder="请输入密码"
            show-password
            @keyup.enter="handleBind"
          />
        </ElFormItem>
      </ElForm>
      <div v-if="market.provider === 'mock'" class="store-mock-tip">
        当前为 Mock 模式：任意账号 + 密码 <code>123456</code> 可登录
      </div>
      <template #footer>
        <ElButton @click="bindVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="binding" @click="handleBind">绑定</ElButton>
      </template>
    </ElDialog>

    <!-- 购买引导弹窗 -->
    <ElDialog v-model="buyVisible" title="购买应用" width="420px">
      <p class="store-buy-text">{{ buyInfo.message }}</p>
      <p v-if="buyInfo.price" class="store-buy-price">价格：¥{{ buyInfo.price }}</p>
      <template #footer>
        <ElButton @click="buyVisible = false">关闭</ElButton>
        <ElButton v-if="buyInfo.buy_url" type="primary" @click="openBuyUrl">前往购买</ElButton>
      </template>
    </ElDialog>

    <!-- 应用升级确认弹窗（预检后由 storeUtils 单例驱动） -->
    <StoreUpgradeDialog />
  </ElContainer>
</template>
<script setup lang="ts">
  import { computed, onMounted, reactive, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import StoreDetail from './detail.vue'
  import storeApi, {
    type MarketStatus,
    type StoreApp,
    type StoreCategory
  } from '@/api/plugin/store'
  import {
    getCategoryIcon,
    formatPrice,
    confirmStoreUpgrade,
    presentCascadeResult
  } from './storeUtils'
  import StoreUpgradeDialog from './modules/store-upgrade-dialog.vue'

  const loading = ref(false)
  const installingId = ref<string>('')
  const updatingId = ref<string>('')

  const activeTop = ref('all')
  const activeCategory = ref('')
  const keyword = ref('')
  const selectedApp = ref<StoreApp>()

  const categories = ref<StoreCategory[]>([])
  const apps = ref<StoreApp[]>([])
  const currentPage = ref(1)
  const pageSize = ref(20)
  const total = ref(0)

  /** 市场账号状态 */
  const market = reactive<Partial<MarketStatus>>({
    bound: false,
    instance_id: '',
    provider: 'mock'
  })
  const bindVisible = ref(false)
  const binding = ref(false)
  const bindForm = reactive({ username: '', password: '' })

  /** 购买引导 */
  const buyVisible = ref(false)
  const buyInfo = reactive({ message: '', price: 0, buy_url: '' })

  const topNav = [
    { key: 'all', label: '全部', icon: 'ri:apps-line' },
    { key: 'recommend', label: '推荐', icon: 'ri:bookmark-3-line' },
    { key: 'installed', label: '已安装', icon: 'ri:download-line' }
  ]

  const activeCategoryLabel = computed(() => {
    const cat = categories.value.find((c) => c.key === activeCategory.value)
    return cat?.label || '全部应用'
  })

  /** 加载市场账号状态 */
  const loadMarketStatus = async () => {
    try {
      const res = await storeApi.marketStatus()
      Object.assign(market, res)
    } catch (e) {
      console.error('加载市场账号状态失败', e)
    }
  }

  /** 加载分类 */
  const loadCategories = async () => {
    try {
      const res = await storeApi.categories()
      categories.value = [{ key: '', label: '全部', icon: 'ri:apps-line' }, ...res]
    } catch (e) {
      console.error('加载分类失败', e)
    }
  }

  /** 加载应用列表（分页） */
  const loadApps = async () => {
    loading.value = true
    try {
      const res = await storeApi.list({
        category: activeTop.value === 'all' ? activeCategory.value || undefined : undefined,
        keyword: keyword.value || undefined,
        recommend: activeTop.value === 'recommend' ? 1 : undefined,
        installed: activeTop.value === 'installed' ? 1 : undefined,
        page: currentPage.value,
        limit: pageSize.value
      })
      apps.value = res.list
      total.value = res.total
    } catch (e) {
      console.error(e)
    } finally {
      loading.value = false
    }
  }

  /** 清空关键词或回车搜索：回到第一页并重新加载列表 */
  const handleSearch = () => {
    currentPage.value = 1
    loadApps()
  }

  const handleTopNavChange = (key: string) => {
    activeTop.value = key
    if (key === 'installed') {
      activeCategory.value = ''
    }
    currentPage.value = 1
    loadApps()
  }

  const handleCategoryChange = (key: string) => {
    activeCategory.value = key
    // 从“已安装”导航点击分类时自动切回“全部”
    if (activeTop.value === 'installed') {
      activeTop.value = 'all'
    }
    currentPage.value = 1
    loadApps()
  }

  const handlePageChange = (page: number) => {
    currentPage.value = page
    loadApps()
  }

  const handleSizeChange = (size: number) => {
    pageSize.value = size
    currentPage.value = 1
    loadApps()
  }

  /** 打开详情（列表数据不含 changelog 等完整字段，异步拉取详情合并） */
  const openDetail = async (app: StoreApp) => {
    selectedApp.value = app
    try {
      const res = await storeApi.detail(app.id)
      if (res) {
        selectedApp.value = { ...app, ...res }
      }
    } catch (e) {
      console.error('加载应用详情失败', e)
    }
  }

  /** 绑定市场账号 */
  const handleBind = async () => {
    if (!bindForm.username || !bindForm.password) {
      ElMessage.warning('请输入账号和密码')
      return
    }
    binding.value = true
    try {
      await storeApi.marketLogin({ ...bindForm })
      bindVisible.value = false
      bindForm.password = ''
      await loadMarketStatus()
      currentPage.value = 1
      await loadApps()
    } catch {
      // 拦截器已处理错误提示
    } finally {
      binding.value = false
    }
  }

  /** 解绑市场账号 */
  const handleUnbind = async () => {
    try {
      await ElMessageBox.confirm('解绑后将无法下载和更新市场应用，确定解绑？', '提示', {
        type: 'warning'
      })
    } catch {
      return
    }
    try {
      const status = await storeApi.marketLogout()
      // 直接使用接口返回的状态更新 UI
      if (status) {
        Object.assign(market, status)
      }
      currentPage.value = 1
      await loadApps()
    } catch {
      // 拦截器已处理错误提示
    }
  }

  /** 未绑定账号时引导绑定 */
  const ensureBound = (): boolean => {
    if (!market.bound) {
      bindVisible.value = true
      ElMessage.warning('请先绑定应用插件账号')
      return false
    }
    return true
  }

  /** 安装应用（付费未购买时后端返回 need_purchase + buy_url，统一走购买引导） */
  const handleInstall = async (app: StoreApp) => {
    if (!ensureBound()) return

    // 二次确认
    try {
      await ElMessageBox.confirm(`确定要安装「${app.title}」吗？`, '安装确认', { type: 'info' })
    } catch {
      return
    }

    installingId.value = app.id
    try {
      const res = (await storeApi.install(app.id)) as any
      if (res?.need_purchase) {
        showBuyDialog(res)
        return
      }
      // 安装成功提示：应用市场不直接展示打开按钮，引导去管理应用中心启用
      ElMessageBox.alert('应用安装成功！请前往【管理应用】启用该插件后即可使用。', '安装成功', {
        type: 'success',
        confirmButtonText: '我知道了'
      })
      selectedApp.value = undefined
      await loadApps()
    } catch (e: any) {
      if (String(e.message || '').includes('绑定')) {
        bindVisible.value = true
      }
    } finally {
      installingId.value = ''
    }
  }

  /** 更新应用：先预检→（需级联则确认）→升级→呈现结果 */
  const handleUpdate = async (app: StoreApp) => {
    if (!ensureBound()) return
    updatingId.value = app.id
    try {
      const decision = await confirmStoreUpgrade(app.id)
      if (decision.action === 'purchase') {
        showBuyDialog(decision.buy)
        return
      }
      if (decision.action === 'none') {
        await loadApps()
        return
      }

      const res = (await storeApi.update(app.id, decision.cascade)) as any

      if (decision.cascade) {
        // 级联：刷新列表后展示重载/回滚结果
        await loadApps()
        const shown = await presentCascadeResult(res)
        if (shown.needPurchase) showBuyDialog(shown.needPurchase)
        else if (res?.ok) selectedApp.value = undefined
        return
      }

      // 非级联：res 为 enriched plugin（拦截器已提示“更新成功”）或 need_purchase
      if (res?.need_purchase) {
        showBuyDialog(res)
        return
      }
      selectedApp.value = undefined
      await loadApps()
    } catch (e: any) {
      if (String(e?.message || '').includes('绑定')) {
        bindVisible.value = true
      }
      // 级联确认取消 / 其余错误：拦截器或 confirm 已处理
    } finally {
      updatingId.value = ''
    }
  }

  const showBuyDialog = (info: { message?: string; price?: number; buy_url?: string }) => {
    buyInfo.message = info.message || '该应用为付费应用，当前市场账号未购买'
    buyInfo.price = info.price || 0
    buyInfo.buy_url = info.buy_url || ''
    buyVisible.value = true
  }

  const openBuyUrl = () => {
    if (buyInfo.buy_url) window.open(buyInfo.buy_url, '_blank')
  }

  /** 详情弹窗中安装/更新成功后刷新 */
  const handleInstalled = async () => {
    selectedApp.value = undefined
    currentPage.value = 1
    await loadApps()
  }

  onMounted(() => {
    loadMarketStatus()
    loadCategories()
    loadApps()
  })
</script>
<style scoped lang="scss">
  .store-page {
    min-height: calc(100vh - 96px);
    color: var(--art-gray-900);
    background: var(--default-box-color);
  }

  .store-header {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 50px;
    border-bottom: 1px solid var(--default-border);

    h2 {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
    }
  }

  .store-header-right {
    position: absolute;
    right: 16px;
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .store-market-account {
    display: flex;
    gap: 4px;
    align-items: center;
    font-size: 13px;
    color: var(--art-gray-600);
  }

  .store-back {
    position: absolute;
    left: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    padding: 0;
    color: var(--art-gray-900);
    background: var(--default-box-color);
    border: 1px solid var(--default-border);
    border-radius: 7px;

    &:hover {
      color: var(--el-color-primary);
      background: var(--art-active-color);
      border-color: var(--el-color-primary-light-7);
    }
  }

  .store-body {
    display: grid;
    grid-template-columns: 198px 1fr;
  }

  .store-sidebar {
    padding: 28px 16px;
    border-right: 1px solid var(--default-border);
  }

  .store-nav-top,
  .store-category-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .store-sidebar-divider {
    height: 1px;
    margin: 14px 10px 10px;
    background: var(--default-border);
  }

  .store-nav-item {
    display: flex;
    gap: 10px;
    align-items: center;
    width: 100%;
    height: 36px;
    padding: 0 10px;
    font-size: 14px;
    font-weight: 500;
    color: var(--art-gray-900);
    text-align: left;
    cursor: pointer;
    background: transparent;
    border: 0;
    border-radius: 4px;
    transition: all 0.2s ease;

    &:hover,
    &.is-active {
      color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
    }
  }

  .store-nav-item--top {
    height: 38px;

    :deep(.art-icon) {
      font-size: 17px;
    }
  }

  .store-content {
    padding: 24px 54px 42px;
  }

  .store-search-wrap {
    max-width: 800px;
    margin: 0 auto 48px;

    :deep(.el-input__wrapper) {
      height: 40px;
      border-radius: 5px;
      box-shadow: 0 0 0 1px var(--default-border) inset;
    }
  }

  .store-search-icon {
    font-size: 19px;
    color: var(--el-color-primary);
  }

  .store-section {
    max-width: 1370px;
    margin: 0 auto;

    h3 {
      margin: 0 0 16px 4px;
      font-size: 18px;
      font-weight: 700;
    }
  }

  .store-app-list {
    min-height: 300px;
  }

  .store-app-grid {
    row-gap: 16px;
  }

  .store-app-col {
    display: flex;
  }

  .store-app-card {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    width: 100%;
    min-height: 74px;
    padding: 16px;
    background: var(--default-box-color);
    border: 1px solid var(--default-border);
    border-radius: 8px;
    box-shadow: 0 1px 2px rgb(16 24 40 / 3%);
    cursor: pointer;
    transition: all 0.2s ease;

    &:hover {
      border-color: var(--el-color-primary-light-7);
      box-shadow: 0 10px 24px rgb(15 23 42 / 8%);
      transform: translateY(-2px);
    }
  }

  .store-app-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    overflow: hidden;
    font-size: 23px;
    color: var(--el-color-white);
    border-radius: 5px;

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  }

  .store-app-info {
    min-width: 0;

    h4 {
      display: flex;
      gap: 6px;
      align-items: center;
      margin: 0 0 5px;
      overflow: hidden;
      font-size: 14px;
      font-weight: 700;
      color: var(--art-gray-900);
      white-space: nowrap;

      // 标题文本可收缩省略，标签不可压缩
      .store-app-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
      }
    }

    p {
      margin: 0;
      overflow: hidden;
      font-size: 12px;
      color: var(--art-gray-500);
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .store-paid-tag {
    flex-shrink: 0;
  }

  .store-app-action {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-end;
    min-width: 62px;
  }

  .store-bind-tip {
    margin-bottom: 16px;
  }

  .store-mock-tip {
    padding: 8px 12px;
    margin-top: 4px;
    font-size: 12px;
    color: var(--art-gray-500);
    background: var(--art-active-color);
    border-radius: 6px;

    code {
      color: var(--el-color-primary);
    }
  }

  .store-buy-text {
    margin: 0 0 8px;
    font-size: 14px;
    color: var(--art-gray-800);
  }

  .store-buy-price {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: var(--el-color-danger);
  }

  .store-pagination {
    display: flex;
    justify-content: flex-end;
    padding: 18px 0 4px;
  }

  @media (max-width: 1080px) {
    .store-content {
      padding: 24px;
    }
  }

  @media (max-width: 760px) {
    .store-body {
      display: block;
    }

    .store-sidebar {
      display: none;
    }
  }
</style>
