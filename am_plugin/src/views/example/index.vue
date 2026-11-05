<!-- 插件接口测试示例页：演示 pluginRequest 的自动前缀能力 -->
<template>
  <div class="example-page">
    <!-- 顶部说明卡 -->
    <ElCard shadow="never" class="mb-4">
      <template #header>
        <div class="card-hd">
          <span class="hd-title">pluginRequest 自动前缀演示</span>
          <ElTag type="success" effect="dark">{{ pluginName }}</ElTag>
        </div>
      </template>
      <ElAlert
        type="info"
        :closable="false"
        show-icon
        title="写法对比"
      >
        <template #default>
          <div class="compare">
            <div class="row">
              <span class="k">前端代码：</span>
              <code>pluginRequest.get({ '{ url: <span class="hl">/test/ping</span>' } })</code>
            </div>
            <div class="row">
              <span class="k">实际请求：</span>
              <code>{{ requestBase }}/app/<span class="hl">{{ pluginName }}</span>/test/ping</code>
            </div>
            <div class="row tip">
              <span class="k">说明：</span>
              <span>
                <code>/app/{{ pluginName }}</code> 前缀由 <code>pluginRequest</code> 自动拼接（读取
                <code>VITE_PLUGIN_NAME</code>），插件开发者只写业务路径即可。
              </span>
            </div>
          </div>
        </template>
      </ElAlert>
    </ElCard>

    <!-- 接口测试按钮组 -->
    <ElCard shadow="never" class="mb-4">
      <template #header><span class="hd-title">接口测试</span></template>
      <ElSpace wrap :size="12">
        <ElButton type="primary" :loading="loading.ping" @click="handlePing">GET /test/ping</ElButton>
        <ElButton type="primary" :loading="loading.info" @click="handleInfo">GET /test/info</ElButton>
        <ElButton type="success" :loading="loading.user" @click="handleCurrentUser">
          GET /test/currentUser
        </ElButton>
        <ElButton type="warning" :loading="loading.echo" @click="handleEcho">
          POST /test/echo（携带随机数据）
        </ElButton>
      </ElSpace>
      <div v-if="lastResult" class="result-wrap">
        <div class="result-hd">
          <span>请求结果</span>
          <ElTag :type="lastResult.ok ? 'success' : 'danger'" size="small">
            {{ lastResult.ok ? '成功' : '失败' }}
          </ElTag>
          <span class="result-url">{{ lastResult.url }}</span>
        </div>
        <pre class="result-body" :class="lastResult.ok ? 'ok' : 'err'">{{ lastResult.text }}</pre>
      </div>
    </ElCard>

    <!-- 分页列表演示 -->
    <ElCard shadow="never">
      <template #header>
        <div class="card-hd">
          <span class="hd-title">GET /test/list（分页 + 关键词演示）</span>
          <ElInput
            v-model="listQuery.keyword"
            placeholder="按名称搜索，如：记录 12"
            clearable
            style="width: 220px"
            @keyup.enter="loadList"
            @clear="loadList"
          >
            <template #append>
              <ElButton :loading="loading.list" @click="loadList">搜索</ElButton>
            </template>
          </ElInput>
        </div>
      </template>

      <ElTable :data="listData" border stripe v-loading="loading.list">
        <ElTableColumn prop="id" label="ID" width="80" />
        <ElTableColumn prop="name" label="名称" />
        <ElTableColumn prop="status" label="状态" width="100">
          <template #default="{ row }">
            <ElTag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '启用' : '禁用' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="created_at" label="创建时间" width="180" />
      </ElTable>

      <ElPagination
        v-model:current-page="listQuery.page"
        v-model:page-size="listQuery.pageSize"
        :total="listQuery.total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next"
        class="pagination"
        @size-change="loadList"
        @current-change="loadList"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ref, reactive, onMounted } from 'vue'
  import testApi from '@/api/plugin/test'
  import { getPluginName, getApiBaseURL } from '@/utils/env'

  defineOptions({ name: 'PluginApiExample' })

  const pluginName = getPluginName()
  const requestBase = getApiBaseURL()

  /** 加载状态 */
  const loading = reactive({
    ping: false,
    info: false,
    user: false,
    echo: false,
    list: false
  })

  /** 最近一次测试结果 */
  const lastResult = ref<{ url: string; text: string; ok: boolean } | null>(null)

  /** 通用调用封装：统一记录 URL 与结果，展示到结果面板 */
  async function callApi(label: string, flag: keyof typeof loading, fn: () => Promise<any>) {
    loading[flag] = true
    lastResult.value = { url: label, text: '请求中…', ok: true }
    try {
      const data = await fn()
      lastResult.value = {
        url: label,
        text: JSON.stringify(data, null, 2) || '(无返回)',
        ok: true
      }
    } catch (e: any) {
      lastResult.value = {
        url: label,
        text: e?.message || String(e),
        ok: false
      }
    } finally {
      loading[flag] = false
    }
  }

  const handlePing = () => callApi('GET /test/ping', 'ping', () => testApi.ping())
  const handleInfo = () => callApi('GET /test/info', 'info', () => testApi.info())
  const handleCurrentUser = () =>
    callApi('GET /test/currentUser', 'user', () => testApi.currentUser())

  const handleEcho = () => {
    const payload = {
      randomId: Math.floor(Math.random() * 10000),
      message: 'hello from am_im plugin',
      timestamp: Date.now(),
      nested: { a: 1, b: [2, 3] }
    }
    return callApi('POST /test/echo', 'echo', () => testApi.echo(payload))
  }

  /** 列表分页数据 */
  const listData = ref<any[]>([])
  const listQuery = reactive({ page: 1, pageSize: 10, keyword: '', total: 0 })

  const loadList = async () => {
    loading.list = true
    try {
      const res = await testApi.list({
        page: listQuery.page,
        pageSize: listQuery.pageSize,
        keyword: listQuery.keyword || undefined
      })
      listData.value = res.list || []
      listQuery.total = res.total || 0
    } catch (e: any) {
      ElMessage.error(e?.message || '加载失败')
    } finally {
      loading.list = false
    }
  }

  onMounted(() => {
    loadList()
  })
</script>

<style scoped lang="scss">
  .example-page {
    padding: 16px;
  }

  .mb-4 {
    margin-bottom: 16px;
  }

  .card-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .hd-title {
    font-weight: 600;
    font-size: 14px;
  }

  .compare {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 13px;
    line-height: 1.7;

    .row {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .row.tip {
      color: var(--el-text-color-secondary);
      font-size: 12px;
    }

    .k {
      color: var(--el-text-color-secondary);
      min-width: 76px;
      flex-shrink: 0;
    }

    code {
      background: var(--el-fill-color-light);
      padding: 2px 8px;
      border-radius: 4px;
      font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
      font-size: 12px;
    }

    .hl {
      color: var(--el-color-primary);
      font-weight: 600;
    }
  }

  .result-wrap {
    margin-top: 16px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    overflow: hidden;
  }

  .result-hd {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: var(--el-fill-color-light);
    font-size: 13px;
    font-weight: 500;
  }

  .result-url {
    margin-left: auto;
    font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .result-body {
    margin: 0;
    padding: 12px;
    font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
    font-size: 12px;
    line-height: 1.6;
    max-height: 320px;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;

    &.ok {
      background: var(--el-color-success-light-9);
      color: var(--el-color-success-dark-2);
    }

    &.err {
      background: var(--el-color-danger-light-9);
      color: var(--el-color-danger-dark-2);
    }
  }

  .pagination {
    margin-top: 16px;
    justify-content: flex-end;
  }
</style>
