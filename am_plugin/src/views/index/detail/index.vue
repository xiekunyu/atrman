<!-- 前台内容详情页示例：演示公开接口（$noNeedLogin）+ 路由参数渲染，无登录依赖 -->
<template>
  <div class="index-detail">
    <div class="detail-back">
      <RouterLink :to="INDEX_HOME_PATH">← 返回首页</RouterLink>
    </div>

    <article v-if="post" class="detail-article">
      <h1 class="detail-title">{{ post.title }}</h1>
      <time class="detail-date">{{ post.created_at }}</time>
      <div class="detail-content">
        <p v-for="(line, i) in contentLines" :key="i">{{ line }}</p>
      </div>
    </article>

    <div v-else-if="loading" class="detail-loading">
      <ElSkeleton animated>
        <template #template>
          <ElSkeletonItem variant="h3" style="width: 60%" />
          <ElSkeletonItem variant="text" style="margin-top: 16px" />
          <ElSkeletonItem variant="text" style="width: 90%" />
          <ElSkeletonItem variant="text" style="width: 85%" />
        </template>
      </ElSkeleton>
    </div>

    <div v-else class="detail-empty">
      <p>{{ loadError ? '内容加载失败，请稍后再试' : '内容不存在' }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted, watch } from 'vue'
  import { useRoute } from 'vue-router'
  import { ElSkeleton, ElSkeletonItem } from 'element-plus'
  import { INDEX_HOME_PATH } from '@/router/modules/index'
  import indexPostsApi, { type IndexPost } from '@/api/index/posts'

  defineOptions({ name: 'IndexDetail' })

  const route = useRoute()

  const loading = ref(false)
  const loadError = ref(false)
  const post = ref<IndexPost | null>(null)

  const contentLines = computed(() => (post.value?.content || '').split('\n').filter(Boolean))

  const loadDetail = async (id: string) => {
    loading.value = true
    loadError.value = false
    post.value = null
    try {
      post.value = await indexPostsApi.detail(id)
    } catch (e) {
      loadError.value = true
      console.warn('[Index] 加载详情失败：', e)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => loadDetail(String(route.params.id || '')))
  watch(
    () => route.params.id,
    (id) => loadDetail(String(id || ''))
  )
</script>

<style lang="scss" scoped>
  .index-detail {
    max-width: 760px;
    margin: 0 auto;
    padding: 24px 24px 48px;
  }

  .detail-back {
    margin-bottom: 16px;
    font-size: 14px;

    a {
      color: var(--el-text-color-secondary);
      text-decoration: none;

      &:hover {
        color: var(--theme-color, var(--el-color-primary));
      }
    }
  }

  .detail-article {
    padding: 28px;
    border: 1px solid var(--art-card-border);
    border-radius: 12px;
    background: var(--default-box-color);

    .detail-title {
      margin: 0 0 8px;
      font-size: 22px;
    }

    .detail-date {
      font-size: 12px;
      color: var(--el-text-color-placeholder);
    }

    .detail-content {
      margin-top: 20px;
      font-size: 15px;
      line-height: 1.9;
      color: var(--el-text-color-regular);

      p {
        margin: 0 0 12px;
      }
    }
  }

  .detail-loading,
  .detail-empty {
    padding: 40px;
    border: 1px solid var(--art-card-border);
    border-radius: 12px;
    background: var(--default-box-color);
  }

  .detail-empty {
    font-size: 14px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
</style>
