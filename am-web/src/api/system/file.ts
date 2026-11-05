import request from '@/utils/http'
import { createCrudApi } from '../crud'

/**
 * 文件 API
 */
export default {
  ...createCrudApi('/sys/file', { list: { decompress: true } }),

  getTree(data: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/file/getTree',
      data
    })
  },
  /**
   * 检查文件 MD5 是否存在（秒传接口）
   * @param data 包含 md5 的数据
   */
  checkMd5(data: { md5: string; pid?: number | string }) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/file/checkMd5',
      data
    })
  },
  /**
   * 合并分片文件
   * @param data 包含 md5, filename 等信息
   */
  merge(data: {
    md5: string
    filename: string
    file_type?: number
    pid?: number
    storage_id?: number
  }) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/file/merge',
      data
    })
  },
  /**
   * 上传文件到本地存储
   * @param file 文件对象
   * @param pid 父目录ID
   * @param onProgress 上传进度回调
   */
  uploadLocal(file: File, params: Record<string, any>, onProgress?: (percent: number) => void) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('pid', params?.pid || '0')
    formData.append('storage_id', params?.storage_id || '0')

    return request.post<Api.Common.ApiData>({
      url: '/pub/system/upload',
      data: formData,
      onUploadProgress: (progressEvent) => {
        if (progressEvent.total && onProgress) {
          const percent = Math.round((progressEvent.loaded * 100) / progressEvent.total)
          onProgress(percent)
        }
      }
    })
  },
  /** 通过 URL 上传文件 */
  uploadUrl(params: Record<string, any>) {
    return request.post<Api.Common.ApiData>({
      url: `/pub/system/uploadUrl`,
      data: params
    })
  }
}
