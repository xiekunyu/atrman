<!-- 角色管理页面 -->
<template>
  <div class="art-full-height">
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
      >
        <template #left>
          <ElSpace wrap>
            <ElButton @click="showDialog('add')" v-ripple v-auth="'system:remote:save'"
              >新建</ElButton
            >
            <ElButton
              @click="batchDelete(remoteApi.batchDelete, refreshData)"
              type="danger"
              plain
              :disabled="selectedRows.length == 0"
              v-ripple
              v-auth="'system:remote:batchDelete'"
            >
              删除
            </ElButton>
          </ElSpace>
        </template>
      </ArtTableHeader>

      <!-- 表格 -->
      <ArtTable
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @selection-change="handleSelectionChange"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <!-- 操作列 -->
        <template #operation="{ row }">
          <div class="flex">
            <ArtButtonTable
              type="edit"
              v-auth="'system:remote:update'"
              @click="showDialog('edit', row)"
            />
            <ArtButtonTable
              type="delete"
              v-auth="'system:remote:delete'"
              v-if="!row.is_sys"
              @click="deleteRow(row, remoteApi.delete, refreshData)"
            />
          </div>
        </template>
      </ArtTable>
    </ElCard>

    <!-- 远程数据编辑弹窗 -->
    <RemoteEditDialog
      v-model="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      @success="handleSubmit"
    />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import remoteApi from '@/api/system/remote'
  import RemoteEditDialog from './modules/edit-dialog.vue'
  import { tableOperation } from '@/mixins/tableOperation'

  defineOptions({ name: 'Remote' })
  const {
    dialogType,
    dialogVisible,
    dialogData,
    showDialog,
    selectedRows,
    batchDelete,
    handleSelectionChange,
    deleteRow
  } = tableOperation()

  const showSearchBar = ref(false)

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    handleSizeChange,
    handleCurrentChange,
    refreshData,
    refreshCreate,
    refreshUpdate
  } = useTable({
    performance: {
      debounceTime: 500 // 搜索防抖
    },
    // 核心配置
    core: {
      apiFn: remoteApi.list,
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'id', label: 'ID', width: 100 },
        { prop: 'name', label: '名称', minWidth: 120 },
        { prop: 'code', label: '标识', minWidth: 120 },
        { prop: 'api_url', label: '接口地址', minWidth: 120 },
        { prop: 'label_field', label: '标签字段', minWidth: 120 },
        { prop: 'value_field', label: '值字段', minWidth: 120 },
        { prop: 'status', label: '状态', width: 70, amType: 'dict', dict: 'status' },
        { prop: 'is_sys', label: '数据类型', width: 100, amType: 'dict', dict: 'is_sys' },
        { prop: 'operation', label: '操作', width: 100, fixed: 'right', useSlot: true }
      ]
    }
  })

  const handleSubmit = () => {
    if (dialogType.value === 'add') {
      refreshCreate()
    } else {
      refreshUpdate()
    }
  }
</script>
