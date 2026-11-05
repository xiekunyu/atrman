import { ElMessage, ElMessageBox, ElNotification } from 'element-plus'

export const uiBridgeService = {
  message(params: any = {}) {
    if (typeof params === 'string') {
      ElMessage(params)
      return true
    }

    ElMessage({
      type: params.type || 'info',
      message: params.message || '',
      duration: params.duration
    })
    return true
  },

  notify(params: any = {}) {
    ElNotification({
      type: params.type || 'info',
      title: params.title || '提示',
      message: params.message || '',
      duration: params.duration
    })
    return true
  },

  async confirm(params: any = {}) {
    try {
      await ElMessageBox.confirm(params.message || '确认执行该操作？', params.title || '确认', {
        type: params.type || 'warning',
        confirmButtonText: params.confirmButtonText || '确定',
        cancelButtonText: params.cancelButtonText || '取消'
      })
      return true
    } catch {
      return { cancelled: true }
    }
  },

  async alert(params: any = {}) {
    await ElMessageBox.alert(params.message || '', params.title || '提示', {
      type: params.type || 'info',
      confirmButtonText: params.confirmButtonText || '确定'
    })
    return true
  }
}
