import request from '@/utils/http'

export default {
  /** 获取备份列表 */
  list(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/sys/backup/index',
      params,
      decompress: true
    })
  },

  /** 获取备份详情 */
  read(id: number) {
    return request.get<Api.Common.ApiData>({
      url: `/sys/backup/read?id=${id}`
    })
  },

  /** 创建系统备份 */
  create(data: Record<string, any> = {}) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/create',
      data
    })
  },

  /** 删除备份 */
  delete(params: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/delete',
      data: params
    })
  },

  /** 批量删除备份 */
  batchDelete(params: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/batchDelete',
      data: params
    })
  },

  /** 下载备份文件 */
  download(id: number) {
    return request.get<Blob>({
      url: `/sys/backup/download?id=${id}`,
      responseType: 'blob'
    })
  },

  /** 恢复前兼容性检查 */
  check(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/check',
      data
    })
  },

  /** 执行系统恢复 */
  restore(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/restore',
      data
    })
  },

  /** 上传备份文件 */
  upload(data: FormData) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/backup/upload',
      data,
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
  }
}
