<!-- 菜单管理页面 -->
<template>
  <div class="menu-page art-full-height">
    <!-- 搜索栏 -->
    <ArtSearchBar
      v-model="searchParams"
      :items="formItems"
      :showExpand="false"
      @search="handleSearch"
      @reset="resetSearchParams"
    />

    <ElCard class="art-table-card" shadow="never">
      <!-- 表格头部 -->
      <ArtTableHeader
        :showZebra="false"
        :loading="loading"
        v-model:columns="columnChecks"
        @refresh="refreshData"
      >
        <template #left>
          <ElButton @click="showDialog('add')" v-ripple v-auth="'system:menu:save'"> 新建 </ElButton>
          <ElButton @click="batchDelete(menuApi.batchDelete,refreshData)" type="danger" plain :disabled="selectedRows.length==0" v-ripple v-auth="'system:menu:batchDelete'"> 删除 </ElButton>
          <ElButton @click="toggleExpand" v-ripple>
            {{ isExpanded ? '收起' : '展开' }}
          </ElButton>
          <ElButton
            @click="handleSyncPermission"
            :loading="syncing"
            v-ripple
            v-auth="'system:menu:syncPermission'"
          >
            同步按钮权限
          </ElButton>
        </template>
      </ArtTableHeader>

      <ArtTable
        ref="tableRef"
        rowKey="id"
        :loading="loading"
        :columns="columns"
        :data="data"
        :stripe="false"
        :tree-props="{ children: 'children', hasChildren: 'hasChildren' }"
        :default-expand-all="false"
        @selection-change="handleSelectionChange"
      >
      <template #operation="{ row }">
          <div class="flex justify-end">
            <ArtButtonTable
            v-if="row.type !== 3"
              v-auth="'system:menu:save'"
              type="add"
              @click="handleAddMenu(row)"
            />
            <ArtButtonTable
              v-auth="'system:menu:update'"
              type="edit"
              @click="showDialog('edit',row)"
            />
            <ArtButtonTable
              v-auth="'system:menu:delete'"
              type="delete"
              @click="deleteRow(row,menuApi.delete,refreshData)"
            />
          </div>
        </template>

      </ArtTable>

      <!-- 菜单弹窗 -->
      <MenuDialog
        v-model:visible="dialogVisible"
        :type="dialogType"
        :editData="dialogData"
        :lockType="lockMenuType"
        :pid="pid"
        @submit="handleSubmit"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'
  import { useTable } from '@/hooks/core/useTable'
  import type { AppRouteRecord } from '@/types/router'
  import MenuDialog from './modules/menu-dialog.vue'
  import menuApi from '@/api/system/menu'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useRemoteDataStore } from '@/store/modules/remoteData'
  import { ElMessage, ElMessageBox } from 'element-plus'

  defineOptions({ name: 'Menus' })

      // 编辑框
  const { dialogType, dialogVisible, dialogData,selectedRows,handleSelectionChange, showDialog, deleteRow,batchDelete } =
    tableOperation()

  const remoteDataStore = useRemoteDataStore()

  // 状态管理
  const isExpanded = ref(false)
  const tableRef = ref()

  const pid = ref(0)
  const lockMenuType = ref(false)

  const formItems = computed(() => [
    { label: '菜单名称', key: 'name', type: 'input', props: { clearable: true } },
    { label: '路由地址', key: 'route', type: 'input', props: { clearable: true } }
  ])


  // 表格列配置
    const {
    columns,
    columnChecks,
    data,
    loading,
    searchParams,
    resetSearchParams,
    refreshData,
    refreshCreate,
    refreshUpdate
  } = useTable({
    performance: {
      debounceTime: 500, // 搜索防抖
    },
    core: {
      apiFn: menuApi.list,
      columnsFactory: () => [
        { type: 'selection' },
        {
          prop: 'name',
          label: '菜单名称',
          width: 300,
          formatter: (row: any) => {
            return h('span', { }, [
                    h(ArtSvgIcon, { icon: row.icon ,style: { width: '18px', height: '18px' }}),
                    h('span', { class: 'ml-2' }, row.name)
                  ])
          }
        },
        { prop: 'type', label: '菜单类型' ,amType: 'dict', dict: 'menu_type',width: 80},
        { prop: 'code', label: '菜单标识' },
        {
          prop: 'path',
          label: '路由',
          formatter: (row: AppRouteRecord) => {
            if (row.meta?.isAuthButton) return ''
            return row.meta?.link || row.path || ''
          }
        },
        {
          prop: 'meta.authList',
          label: '权限标识',
          minWidth:"180",
          formatter: (row: any) => {
            if (row.type === 2) {
              return row.children?.length ? row.children.length + '个权限标识' : ''
            }
            if (row.type === 3) {
              return row.slug || ''
            }
            return ''
          }
        },
        { prop: 'sort', label: '排序', width: 60 },
        { prop: 'status', label: '状态', width: 70, amType: 'dict', dict: 'status'  },
        { prop: 'operation', label: '操作', width: 150, fixed: 'right', useSlot: true  }
      ]}
    }
  )
  
  /**
   * 菜单表单数据类型
   */
  interface MenuFormData {
    name: string
    path: string
    component?: string
    icon?: string
    roles?: string[]
    sort?: number
    [key: string]: any
  }

  const handleSearch = () => {
    refreshData()
  }

  const handleSubmit = (formData: MenuFormData): void => {
    // 清除上级菜单的远程数据缓存，确保下次打开弹窗能获取最新菜单树
    remoteDataStore.clearCacheByCode('auth_menu')
    setTimeout(() => {
      if(dialogType.value === 'add'){
        refreshCreate()
      }else{
        refreshUpdate()
      }
    }, 500);
  }

  // 添加子项
  const handleAddMenu = (row:any)=>{
    pid.value=row.id;
    showDialog('add');
  }


  /** 后端返回的单个归属同步报告 */
  interface SyncScope {
    app_name: string
    created: number
    updated: number
    unchanged: number
    removed: number
    orphan: { id: number; slug: string; name: string }[]
    unattached: { slug: string; segment: string }[]
    ambiguous: { slug: string; segment: string }[]
    conflict: { slug: string; reason: string }[]
    warnings: number
  }

  const syncing = ref(false)

  /**
   * 扫描控制器注解，补齐/校正按钮权限节点。
   * 目录与页面不动；「代码里已不存在的按钮」只报告，不自动删（删了会连带回收角色授权）。
   */
  const handleSyncPermission = async (): Promise<void> => {
    try {
      await ElMessageBox.confirm(
        '扫描基座与已启用插件的控制器注解，按 #[Permission] 补齐并校正按钮权限节点。只处理按钮层，目录与页面不受影响；默认不删除代码里已不存在的节点。',
        '同步按钮权限',
        { type: 'info', confirmButtonText: '开始同步', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    syncing.value = true
    try {
      const scopes = (((await menuApi.syncPermission()) as unknown as SyncScope[]) || []) as SyncScope[]
      const changed = scopes.reduce(
        (total, scope) => total + (scope.created ?? 0) + (scope.updated ?? 0) + (scope.removed ?? 0),
        0
      )
      ElMessage.success(changed > 0 ? `同步完成，共写入 ${changed} 个按钮权限节点` : '按钮权限已是最新')
      refreshData()
    } finally {
      syncing.value = false
    }
  }

  /**
   * 切换展开/收起所有菜单
   */
  const toggleExpand = (): void => {
    isExpanded.value = !isExpanded.value
    nextTick(() => {
      if (tableRef.value?.elTableRef && data.value) {
        const processRows = (rows: AppRouteRecord[]) => {
          rows.forEach((row) => {
            if (row.children?.length) {
              tableRef.value.elTableRef.toggleRowExpansion(row, isExpanded.value)
              processRows(row.children)
            }
          })
        }
        processRows(data.value)
      }
    })
  }
</script>
