<!--
  前台首页示例（CMS 官网原型）
  演示前台 index 板块的标准姿势：
  1. 公开内容只调公开接口（$noNeedLogin），无登录、无 Bridge 也能完整渲染；
  2. 登录态/Bridge 均为可选增强：hasBridge() 时才展示管理员相关 UI；
  3. 会员内容门控请参考 <RequireLogin> 组件用法（§5.5）：未登录不调会员接口、不跳走，
     登录/注册走会员中心同页签票据闭环，回来后落在当前页。本页仅保留公开内容示例。
-->
<template>
  <div class="index-home">
    <section class="hero">
      <h1 class="hero-title">{{ appName }}</h1>
      <p class="hero-desc">
        这是插件「前端公开入口」示例页：任何人直接访问 /app/{插件名} 即可浏览，无需登录、不依赖基座。
      </p>
      <div v-if="hasBridge()" class="hero-admin">
        <span v-if="user?.nickname">当前登录用户：{{ user.nickname }}</span>
        <ElButton v-if="canOpenManage" size="small" type="primary" plain @click="openManage">
          打开管理后台
        </ElButton>
      </div>
    </section>

    <section class="post-list">
      <h2 class="section-title">最新资讯</h2>

      <div v-if="loading" class="post-grid">
        <ElSkeleton v-for="n in 4" :key="n" animated>
          <template #template>
            <ElSkeletonItem variant="rect" style="height: 96px; border-radius: 10px" />
          </template>
        </ElSkeleton>
      </div>

      <div v-else-if="posts.length" class="post-grid">
        <RouterLink
          v-for="post in posts"
          :key="post.id"
          :to="`/index/detail/${post.id}`"
          class="post-card"
        >
          <h3 class="post-title">{{ post.title }}</h3>
          <p class="post-summary">{{ post.summary }}</p>
          <span class="post-date">{{ post.created_at }}</span>
        </RouterLink>
      </div>

      <div v-else class="post-empty">
        <p>{{ loadError ? '公开接口暂不可用（请确认后端插件已安装并启用）' : '暂无内容' }}</p>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted } from 'vue'
  import { ElButton, ElSkeleton, ElSkeletonItem } from 'element-plus'
  import { hasBridge, invoke, getCapabilities } from '@/sdk/bridge'
  import { useUser } from '@/sdk/user'
  import { getPluginMeta } from '@/sdk/entry'
  import AppConfig from '@/config'
  import indexPostsApi, { type IndexPost } from '@/api/index/posts'

  defineOptions({ name: 'IndexHome' })

  const { user } = useUser()
  const appName = computed(() => AppConfig.systemInfo.name)

  const loading = ref(false)
  const loadError = ref(false)
  const posts = ref<IndexPost[]>([])

  // 仅基座环境且具备插件 meta 与导航能力时，才展示「打开管理后台」
  const canOpenManage = computed(() => {
    return hasBridge() && !!getPluginMeta() && getCapabilities().includes('navigation.openPlugin')
  })

  const openManage = () => {
    const meta = getPluginMeta()
    if (!meta) return
    invoke('navigation.openPlugin', { name: meta.name, id: meta.id, entry: 'manage' }).catch(
      () => {}
    )
  }

  const loadPosts = async () => {
    loading.value = true
    try {
      const data = await indexPostsApi.posts()
      posts.value = data?.list || []
    } catch (e) {
      loadError.value = true
      console.warn('[Index] 加载公开内容失败：', e)
    } finally {
      loading.value = false
    }
  }

  onMounted(loadPosts)
</script>

<style lang="scss" scoped>
  .index-home {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 24px 48px;
  }

  .hero {
    padding: 28px;
    margin-bottom: 28px;
    border: 1px solid var(--art-card-border);
    border-radius: 12px;
    background: var(--default-box-color);

    .hero-title {
      margin: 0 0 8px;
      font-size: 24px;
    }

    .hero-desc {
      margin: 0;
      font-size: 14px;
      color: var(--el-text-color-secondary);
    }

    .hero-admin {
      display: flex;
      gap: 12px;
      align-items: center;
      margin-top: 16px;
      font-size: 13px;
      color: var(--el-text-color-regular);
    }
  }

  .section-title {
    margin: 0 0 16px;
    font-size: 18px;
  }

  .post-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }

  .post-card {
    display: block;
    padding: 18px;
    overflow: hidden;
    text-decoration: none;
    border: 1px solid var(--art-card-border);
    border-radius: 10px;
    background: var(--default-box-color);
    transition: box-shadow 0.2s ease;

    &:hover {
      box-shadow: 0 6px 18px rgb(15 23 42 / 8%);
    }

    .post-title {
      margin: 0 0 6px;
      font-size: 15px;
      color: var(--el-text-color-primary);
    }

    .post-summary {
      margin: 0 0 10px;
      overflow: hidden;
      font-size: 13px;
      color: var(--el-text-color-secondary);
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .post-date {
      font-size: 12px;
      color: var(--el-text-color-placeholder);
    }
  }

  .post-empty {
    padding: 48px 0;
    font-size: 14px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
</style>
