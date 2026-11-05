<template>
  <div class="art-full-height">
    <LoginLogSearch
      v-show="showSearchBar"
      v-model="searchForm"
      @search="handleSearch"
      @reset="resetSearchParams"
    />

    <ElCard
      class="art-table-card"
      shadow="never"
      :style="{ 'margin-top': showSearchBar ? '12px' : '0' }"
    >
      <ArtTableHeader
        v-model:columns="columnChecks"
        v-model:showSearchBar="showSearchBar"
        :loading="loading"
        @refresh="refreshData"
      />

      <ArtTable
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @row-dblclick="showDetail"
        @sort-change="handleSortChange"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #status="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'danger'">
            {{ row.status === 1 ? '成功' : '失败' }}
          </ElTag>
        </template>
        <template #operation="{ row }">
          <ArtButtonTable type="view" v-auth="'system:loginLog:read'" @click="showDetail(row)" />
        </template>
      </ArtTable>
    </ElCard>

    <LoginLogDetail v-model:visible="detailVisible" :data="detailData" />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import loginLogApi from '@/api/system/loginLog'
  import LoginLogSearch from './modules/login-log-search.vue'
  import LoginLogDetail from './modules/login-log-detail.vue'

  defineOptions({ name: 'LoginLog' })

  const showSearchBar = ref(false)
  const searchForm = ref({
    keywords: '',
    username: '',
    ip: '',
    status: '',
    create_time: []
  })
  const detailVisible = ref(false)
  const detailData = ref<Record<string, any>>({})

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    getData,
    searchParams,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange,
    handleSortChange,
    refreshData
  } = useTable({
    performance: {
      debounceTime: 500
    },
    core: {
      apiFn: loginLogApi.list,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 90, sortable: true },
        { prop: 'username', label: '用户名', minWidth: 120, showOverflowTooltip: true },
        { prop: 'ip', label: '登录IP', width: 140 },
        { prop: 'location', label: 'IP归属地', minWidth: 180, showOverflowTooltip: true },
        { prop: 'os', label: '操作系统', width: 110 },
        { prop: 'browser', label: '浏览器', width: 110 },
        { prop: 'status', label: '状态', width: 90, useSlot: true },
        { prop: 'message', label: '提示消息', minWidth: 140, showOverflowTooltip: true },
        { prop: 'create_time', label: '登录时间', width: 180, sortable: true },
        { prop: 'operation', label: '操作', width: 90, fixed: 'right', useSlot: true }
      ]
    }
  })

  const showDetail = async (row: Record<string, any>) => {
    detailData.value = (await loginLogApi.read(row.id)) as Record<string, any>
    detailVisible.value = true
  }

  const handleSearch = (params: Record<string, any>) => {
    Object.assign(searchParams, params)
    getData()
  }
</script>
