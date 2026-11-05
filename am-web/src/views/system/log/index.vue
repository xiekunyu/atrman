<template>
  <div class="art-full-height">
    <LogSearch
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
        <template #method="{ row }">
          <ElTag :type="getMethodTagType(row.method)">{{ row.method }}</ElTag>
        </template>
        <template #operation="{ row }">
          <ArtButtonTable type="view" v-auth="'system:log:read'" @click="showDetail(row)" />
        </template>
      </ArtTable>
    </ElCard>

    <LogDetail v-model:visible="detailVisible" :data="detailData" />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import logApi from '@/api/system/log'
  import LogSearch from './modules/log-search.vue'
  import LogDetail from './modules/log-detail.vue'

  defineOptions({ name: 'SystemLog' })

  const showSearchBar = ref(false)
  const searchForm = ref({
    keywords: '',
    username: '',
    business_name: '',
    method: '',
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
      apiFn: logApi.list,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 90, sortable: true },
        { prop: 'business_name', label: '业务名称', minWidth: 150, showOverflowTooltip: true },
        { prop: 'username', label: '操作用户', width: 120 },
        { prop: 'method', label: '请求方式', width: 100, useSlot: true },
        { prop: 'router', label: '请求路由', minWidth: 220, showOverflowTooltip: true },
        { prop: 'ip', label: 'IP地址', width: 140 },
        { prop: 'ip_location', label: 'IP归属地', minWidth: 150, showOverflowTooltip: true },
        { prop: 'remark', label: '备注', width: 100 },
        { prop: 'create_time', label: '操作时间', width: 180, sortable: true },
        { prop: 'operation', label: '操作', width: 90, fixed: 'right', useSlot: true }
      ]
    }
  })

  const getMethodTagType = (method: string) => {
    if (method === 'POST') return 'success'
    if (method === 'PUT') return 'warning'
    if (method === 'DELETE') return 'danger'
    return 'info'
  }

  const showDetail = async (row: Record<string, any>) => {
    detailData.value = (await logApi.read(row.id)) as Record<string, any>
    detailVisible.value = true
  }

  const handleSearch = (params: Record<string, any>) => {
    Object.assign(searchParams, params)
    getData()
  }
</script>
