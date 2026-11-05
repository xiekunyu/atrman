import request from '@/utils/http'

/**
 * 通知管理 API（模板/记录/收件箱/偏好）
 */
export default {
  // ========== 模板管理 ==========
  template: {
    /** 获取模板列表 */
    list(params: Record<string, any>) {
      return request.get<Api.Common.ApiData>({ url: '/notify/template/index', params })
    },

    /** 获取模板详情 */
    read(id: number) {
      return request.get<Api.Common.ApiData>({ url: `/notify/template/read?id=${id}` })
    },

    /** 创建模板 */
    save(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/save', data })
    },

    /** 更新模板 */
    update(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/update', data })
    },

    /** 删除模板 */
    delete(params: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/delete', data: params })
    },

    /** 批量删除模板 */
    batchDelete(params: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/batchDelete', data: params })
    },

    /** 切换模板状态 */
    changeStatus(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/changeStatus', data })
    },

    /** 预览模板内容 */
    preview(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/preview', data })
    },

    /** 测试发送模板 */
    test(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/template/test', data })
    }
  },

  // ========== 发送记录 ==========
  log: {
    /** 获取发送记录列表 */
    list(params: Record<string, any>) {
      return request.get<Api.Common.ApiData>({ url: '/notify/log/index', params })
    },

    /** 获取发送记录详情 */
    read(id: number) {
      return request.get<Api.Common.ApiData>({ url: `/notify/log/read?id=${id}` })
    },

    /** 重试发送记录 */
    retry(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/log/retry', data })
    },

    /** 按渠道批量重试失败记录 */
    retryChannel(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/log/retryChannel', data })
    },

    /** 获取发送统计信息 */
    stats(params: Record<string, any>) {
      return request.get<any>({ url: '/notify/log/stats', params })
    }
  },

  // ========== 站内信收件箱 ==========
  inbox: {
    /** 获取站内信列表 */
    list(params: Record<string, any>) {
      return request.get<Api.Common.ApiData>({ url: '/notify/inbox/index', params })
    },

    /** 获取站内信详情 */
    read(id: number) {
      return request.get<Api.Common.ApiData>({ url: `/notify/inbox/read?id=${id}` })
    },

    /** 标记站内信为已读 */
    markRead(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/inbox/markRead', data })
    },

    /** 标记所有站内信为已读 */
    markAllRead() {
      return request.post<Api.Common.ApiData>({ url: '/notify/inbox/markAllRead' })
    },

    /** 删除站内信 */
    delete(params: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/inbox/delete', data: params })
    },

    /** 批量删除站内信 */
    batchDelete(params: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/inbox/batchDelete', data: params })
    },

    /** 获取未读站内信数量 */
    unreadCount() {
      return request.get<any>({ url: '/notify/inbox/unreadCount' })
    }
  },

  // ========== 通知偏好 ==========
  preference: {
    /** 获取当前用户的通知偏好设置 */
    list() {
      return request.get<any[]>({ url: '/notify/preference/index' })
    },

    /** 保存单个通知偏好设置 */
    save(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/preference/save', data })
    },

    /** 批量保存通知偏好设置 */
    batchSave(data: Record<string, any>) {
      return request.post<Api.Common.ApiData>({ url: '/notify/preference/batchSave', data })
    }
  }
}
