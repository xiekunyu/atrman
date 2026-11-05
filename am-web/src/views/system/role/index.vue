<!-- 角色管理页面 -->
<template>
  <div class="art-full-height">
    <RoleSearch
      v-show="showSearchBar"
      v-model="searchForm"
      @search="handleSearch"
      @reset="resetSearchParams"
    ></RoleSearch>

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
            <ElButton @click="showDialog('add')" v-ripple v-auth="'system:role:save'">新建</ElButton>
            <ElButton @click="batchDelete(roleApi.batchDelete,refreshData)" type="danger" plain :disabled="selectedRows.length==0" v-ripple v-auth="'system:role:batchDelete'"> 删除 </ElButton>
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
                  v-auth="'system:role:update'"
                  @click="showDialog('edit', row)"
                />
                <ArtButtonTable
                  type="delete"
                  v-auth="'system:role:delete'"
                  @click="deleteRow(row,roleApi.delete,refreshData)"
                />
                <ArtButtonTable
                  type="add"
                  icon="ri:key-line"
                  v-auth="'system:role:getPermissions'"
                  @click="handlePermission(row)"
                />
              </div>
            </template>
      </ArtTable>
    </ElCard>

    <!-- 角色编辑弹窗 -->
    <RoleEditDialog
      v-model="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      @success="handleSubmit"
    />

    <!-- 菜单权限弹窗 -->
    <RolePermissionDialog
      v-model="permissionDialog"
      :edit-data="dialogData"
      @success="refreshData"
    />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import roleApi from '@/api/system/role'
  import RoleSearch from './modules/role-search.vue'
  import RoleEditDialog from './modules/role-edit-dialog.vue'
  import RolePermissionDialog from './modules/role-permission-dialog.vue'
  import { tableOperation } from '@/mixins/tableOperation'

  defineOptions({ name: 'Role' })
  type RoleListItem = Api.SystemManage.RoleListItem
  const { dialogType, dialogVisible, dialogData, showDialog,selectedRows,batchDelete, handleSelectionChange, deleteRow } =
    tableOperation()

  // 搜索表单
  const searchForm = ref({
    keywords: undefined,
  })

  const showSearchBar = ref(false)

  const permissionDialog = ref(false)

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
    refreshData,
    refreshCreate,
    refreshUpdate
  } = useTable({
    performance: {
      debounceTime: 500, // 搜索防抖
    },
    // 核心配置
    core: {
      apiFn: roleApi.list,
      // 排除 apiParams 中的属性
      excludeParams: ['daterange'],
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'id', label: '角色ID', width: 100 },
        { prop: 'name', label: '角色名称', minWidth: 120 },
        { prop: 'code', label: '角色编码', minWidth: 120 },
        { prop: 'remark', label: '角色描述', minWidth: 150, showOverflowTooltip: true },
        { prop: 'status', label: '状态', width: 70, amType: 'dict', dict: 'status'  },
        { prop: 'create_time',  label: '创建日期', width: 180, sortable: true  },
        { prop: 'operation', label: '操作', width: 150, fixed: 'right', useSlot: true }
      ]
    }
  })

  const handlePermission = (row: RoleListItem) => {
    showPermissionDialog(row)
  }

  const handleUser = (row: RoleListItem) => {
    // TODO: 调用用户列表接口
    ElMessage.success(`打开用户列表页面，角色ID：${row.id}`)
  }

  const handleSubmit = () => {
    if(dialogType.value === 'add'){
      refreshCreate()
    }else{
      refreshUpdate()
    }
  }

  const handleSearch = (params: Record<string, any>) => {
    // 处理日期区间参数，把 daterange 转换为 startTime 和 endTime
    const { daterange, ...filtersParams } = params
    const [startTime, endTime] = Array.isArray(daterange) ? daterange : [null, null]

    Object.assign(searchParams, { ...filtersParams, startTime, endTime })
    getData()
  }

  const showPermissionDialog = (row?: RoleListItem) => {
    permissionDialog.value = true
    dialogData.value = row as any
  }
</script>
