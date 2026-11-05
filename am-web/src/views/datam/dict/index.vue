<!-- 用户管理页面 -->
<!-- art-full-height 自动计算出页面剩余高度 -->
<!-- art-table-card 一个符合系统样式的 class，同时自动撑满剩余高度 -->
<!-- 更多 useTable 使用示例请移步至 功能示例 下面的高级表格示例或者查看官方文档 -->
<!-- useTable 文档：https://www.artd.pro/docs/zh/guide/hooks/use-table.html -->
<template>
  <div class="art-full-height">
    <div class="box-border flex gap-4 h-full max-md:block max-md:gap-0 max-md:h-auto">
      <div class="flex-shrink-0 h-full max-md:w-full max-md:h-auto max-md:mb-5">
        <ElCard class="am-card-header art-card-xs flex flex-col h-full mt-0" shadow="never">
          <template #header>
            <div class="flex justify-between items-center">
              <b>数据字典</b>
              <div class="flex flex-wrap am-header-actions">
                <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshData" />
                <ArtButtonTable
                  v-auth="'system:dict:save'"
                  type="add"
                  @click="addDictData('add')"
                />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3 mt-2">
              <AmStatCard :value="data.length" label="字典数" />
              <AmStatCard :value="selectedRow.name || '所有字典'" label="当前">
                <template #label>
                  当前
                  <b class="text-g-900" v-if="selectedRow?.id > 0"
                    >：{{ selectedRow.name || '所有字典' }}</b
                  >
                </template>
                <template #value>
                  <div class="flex" v-if="selectedRow?.id > 0">
                    <ArtButtonTable
                      v-auth="'system:dict:update'"
                      type="edit"
                      title="编辑"
                      v-if="selectedRow.is_sys != 1"
                      @click="showDialog('edit', selectedRow)"
                    />
                    <ArtButtonTable
                      type="delete"
                      v-auth="'system:dict:delete'"
                      v-if="selectedRow.is_sys != 1"
                      @click="deleteRow(selectedRow, dictApi.delete, refreshData)"
                    />
                  </div>
                </template>
              </AmStatCard>
            </div>
          </template>

          <!-- 表格 -->
          <ArtTable
            :loading="loading"
            :data="data"
            :columns="columns"
            highlight-current-row
            class="am-table-body"
            @selection-change="handleSelectionChange"
            @current-change="rowChange"
          >
            <!-- 操作列 -->
            <template #operation="{ row }">
              <div class="flex">
                <ArtButtonTable
                  v-if="row.is_sys != 1"
                  v-auth="'system:dict:update'"
                  type="edit"
                  @click="showDialog('edit', row)"
                />
                <ArtButtonTable
                  type="delete"
                  v-auth="'system:dict:delete'"
                  v-if="row.is_sys != 1"
                  @click="deleteRow(row, dictApi.delete, refreshData)"
                />
              </div>
            </template>
          </ArtTable>
        </ElCard>
      </div>

      <div class="flex flex-col flex-1 min-w-0" v-if="selectedId === 0">
        <ElCard
          class="main-card flex flex-col flex-1 min-h-0 art-table-card"
          style="margin-top: 0px"
          shadow="never"
        >
          <el-empty description="请先选择左侧字典类型配置" />
        </ElCard>
      </div>

      <div class="flex flex-col flex-1 min-w-0" v-else>
        <ElCard
          class="main-card flex flex-col flex-1 min-h-0 art-table-card"
          style="margin-top: 0px"
          shadow="never"
        >
          <template #header>
            <div class="flex justify-between items-center">
              <b>标签项</b>
              <div>
                <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshListData" />
                <ArtButtonTable v-auth="'system:dict:save'" type="add" @click="showDialog('add')" />
              </div>
            </div>
          </template>
          <!-- 表格 -->

          <ArtTable
            :data="dataList"
            :columns="listColumns"
            @selection-change="handleSelectionChange"
          >
            <!-- 操作列 -->
            <template #name="{ row }">
              <ElTag
                :style="{
                  backgroundColor: getColor(row.color, 'bg'),
                  borderColor: getColor(row.color, 'border'),
                  color: getColor(row.color, 'text')
                }"
              >
                {{ row.name }}
              </ElTag>
            </template>
            <template #operation="{ row }">
              <div class="flex">
                <ArtButtonTable
                  v-if="row.is_sys != 1"
                  v-auth="'system:dict:update'"
                  type="edit"
                  @click="showDialog('edit', row)"
                />
                <ArtButtonTable
                  type="delete"
                  v-auth="'system:dict:delete'"
                  v-if="row.is_sys != 1"
                  @click="deleteRow(row, dictApi.delete, refreshData)"
                />
              </div>
            </template>
          </ArtTable>
        </ElCard>
      </div>
    </div>
    <!-- 弹窗 -->
    <DictDialog
      v-model:visible="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      :pid="selectedId"
      @submit="handleDialogSubmit"
    />
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import dictApi from '@/api/system/dict'
  import DictDialog from './modules/dict-dialog.vue'
  import { ElTag } from 'element-plus'
  import { getColor } from '@/utils/custom'

  defineOptions({ name: 'Dict' })
  const selectedId = ref(0)
  const selectedRow = ref<any>({})
  // 编辑框
  const { dialogType, dialogVisible, dialogData, showDialog, handleSelectionChange, deleteRow } =
    tableOperation()

  /**
   * 添加字典项
   */
  const addDictData = (type: any) => {
    selectedId.value = 0
    showDialog(type)
  }

  const { columns, data, loading, refreshData } = useTable({
    // 核心配置
    core: {
      apiFn: dictApi.list,
      columnsFactory: () => [
        { prop: 'name', label: '字典名称', width: 140 },
        { prop: 'code', label: '字典编码', width: 180 },
        { prop: 'is_sys', label: '数据类型', width: 80, amType: 'dict', dict: 'is_sys' }
      ]
    }
  })

  // 字段标签列表配置
  const {
    data: dataList,
    columns: listColumns,
    getData: getDataList,
    searchParams: dictSearchParams,
    refreshData: refreshListData
  } = useTable({
    // 核心配置
    core: {
      apiFn: dictApi.list,
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'name', label: '字典标签', useSlot: true },
        { prop: 'value', label: '字典值' },
        { prop: 'color', label: '字典颜色' },
        { prop: 'sort', label: '排序' },
        { prop: 'status', label: '状态', width: 70, amType: 'dict', dict: 'status' },
        { prop: 'operation', label: '操作', width: 100, useSlot: true }
      ]
    }
  })

  /**
   * 处理弹窗提交事件
   */
  const handleDialogSubmit = async () => {
    try {
      dialogVisible.value = false
      dialogData.value = {}
      setTimeout(() => {
        refreshData()
        refreshListData()
      }, 500)
    } catch (error) {
      console.error('提交失败:', error)
    }
  }

  const rowChange = (row: any) => {
    selectedId.value = row.id
    selectedRow.value = row
    Object.assign(dictSearchParams, { pid: row.id })
    getDataList()
  }
</script>
