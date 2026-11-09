import request from '@/utils/http'

export default {
  list(params: Record<string, any>) {
    return request.get<Api.Common.ApiData>({
      url: '/sys/file/index',
      params,
      decompress: true
    })
  },
  /** 检查文件 MD5 是否存在（秒传接口） */
  checkMd5(data: { md5: string }) {
    return request.post<Api.Common.ApiData>({
      url: '/sys/file/checkMd5',
      data
    })
  },
  /** 上传文件到本地存储 */
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
