import { ref, nextTick } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'

/**
 * 表格操作 Composable
 * 表格操作状态管理
 */
export function tableOperation() {   
  // 弹窗相关
  const dialogType = ref('add')
  const dialogVisible = ref(false)
  const dialogData = ref<Partial<Record<string, any>>>({})

  // 选中行
  const selectedRows = ref<Record<string, any>[]>([])

  // 显示弹窗
  const showDialog = (type: string, row?: Record<string, any>): void => {
    dialogType.value = type
    dialogData.value = row || {}
    nextTick(() => {
      dialogVisible.value = true
    })
  }

  // 隐藏弹窗
  const hideDialog = (): void => {
    dialogVisible.value = false
  }

  // 表格行选择变化
  const handleSelectionChange = (selection: Record<string, any>[]): void => {
    selectedRows.value = selection
  }

  // 删除数据
  const deleteRow = (
    row: Record<string, any>,
    apiFn: (params: any) => Promise<any>,
    callback?: () => void
  ): void => {
    ElMessageBox.confirm(`确定要删除该数据吗？`, '删除数据', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'error'
    }).then(() => {
      apiFn({ id: row.id}).then(() => {
        if (callback) callback()
      })
    })
  }

  // 批量删除数据
  const batchDelete = (
    apiFn: (params: any) => Promise<any>,
    callback?: () => void
  ): void => {
    if (selectedRows.value.length === 0) {
      ElMessage.warning('请选择要删除的行')
      return
    }
    ElMessageBox.confirm(
      `确定要删除选中的 ${selectedRows.value.length} 条数据吗？`,
      '删除选中数据',
      {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'error'
      }
    ).then(() => {
      apiFn({ ids: selectedRows.value.map((row) => row.id) }).then(() => {
        if (callback) callback()
        selectedRows.value = []
      })
    })
  }

  // 批量改变状态
  const changeStatus = (
    row: Record<string, any>,
    apiFn: (params: any) => Promise<any>,
    callback?: () => void
  ): void => {
    ElMessageBox.confirm(`确定要改变该数据的状态吗？`, '改变状态', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(() => {
      apiFn({ id: row.id, status: row.status }).then(() => {
        if (callback) callback()
      })
    })
  }

//   导出数据
  const exportData = ()=>{

  }

  return {
    dialogType,
    dialogVisible,
    dialogData,
    selectedRows,
    showDialog,
    hideDialog,
    handleSelectionChange,
    deleteRow,
    batchDelete,
    exportData,
    changeStatus
  }
}
