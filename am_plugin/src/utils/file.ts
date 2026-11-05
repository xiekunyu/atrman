import SparkMD5 from 'spark-md5'

/**
 * 计算文件 MD5（支持大文件，Vue3 + TS）
 * @param file 文件对象
 * @returns md5 字符串
 */
export function getFileMd5(file: File): Promise<string> {
  if(file.size>1024*1024*10){
    return new Promise((resolve, reject) => {
      // 兼容不同浏览器 slice
      const blobSlice = (File.prototype as any).slice || (File.prototype as any).webkitSlice || (File.prototype as any).mozSlice
      const chunkSize = 2 * 1024 * 1024 // 2MB 分片
      const chunks = Math.ceil(file.size / chunkSize)
      let currentChunk = 0

      const spark = new SparkMD5.ArrayBuffer()
      const fileReader = new FileReader()

      // 加载下一片
      function loadNext() {
        const start = currentChunk * chunkSize
        const end = start + chunkSize >= file.size ? file.size : start + chunkSize
        fileReader.readAsArrayBuffer(blobSlice.call(file, start, end))
      }

      // 读取成功
      fileReader.onload = (e) => {
        if (!e.target?.result) {
          reject(new Error('读取文件失败'))
          return
        }
        // 追加到 MD5 计算
        spark.append(e.target.result as ArrayBuffer)
        currentChunk++

        if (currentChunk < chunks) {
          loadNext()
        } else {
          // 计算完成
          const md5 = spark.end()
          resolve(md5)
        }
      }

      // 读取失败
      fileReader.onerror = () => {
        reject(new Error('文件读取异常'))
      }

      // 开始计算
      loadNext()
    })
  }else{
    return getFileMd5Simple(file)
  }
}

export async function getFileMd5Simple(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = (e) => {
      const md5 = SparkMD5.ArrayBuffer.hash(e.target?.result as ArrayBuffer)
      resolve(md5)
    }
    reader.onerror = reject
    reader.readAsArrayBuffer(file)
  })
}

/**
 * 格式化文件大小
 * @param size 文件大小（字节）
 * @returns 格式化后的文件大小字符串
 */
export function formatFileSize(size: number) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(size) / Math.log(1024))
  return `${(size / Math.pow(1024, i)).toFixed(2)} ${units[i]}`
}

export const getFileIcon = (filename: string): string => {
  const ext = filename.split('.').pop()?.toLowerCase() || ''
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico']
  const videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm']
  const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'wma', 'm4a']
  const docExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'txt']
  const zipExts = ['zip', 'rar', '7z', 'tar', 'gz']

  if (imageExts.includes(ext)) return 'ri:file-image-line'
  if (videoExts.includes(ext)) return 'ri:file-video-line'
  if (audioExts.includes(ext)) return 'ri:file-music-line'
  if (docExts.includes(ext)) return 'ri:file-list-2-line'
  if (zipExts.includes(ext)) return 'ri:folder-zip-line'
  return 'ri:file-3-line'
}

const isFullUrl = (url?: string): boolean => {
  return /^(https?:)?\/\//i.test(url || '') || /^(blob:|data:)/i.test(url || '')
}

const joinFilePath = (base: string, path: string): string => {
  if (!base) return path
  if (!path) return base
  return `${base.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`
}

export const getFileUrl = (fileInfo: any, isThumbnail = false): string => {
  if (!fileInfo) return ''

  const fullUrl = fileInfo.full_url || fileInfo.fullUrl || ''
  const base = fileInfo.url || fileInfo.domain || ''
  const path = isThumbnail ? fileInfo.thumb || fileInfo.path || '' : fileInfo.path || fileInfo.thumb || ''

  if (!isThumbnail && fullUrl) return fullUrl
  if (isFullUrl(path)) return path
  if (base && path) return joinFilePath(base, path)
  if (fullUrl) return fullUrl
  return path || base || ''
}

export const downloadFile = async (url: string, fileName: string): Promise<boolean> => {
    if (!url || !fileName) {
      console.error('下载失败: URL 或文件名无效')
      return false
    }

    // 获取认证 token（用于需要鉴权的 API 接口）
    let authHeaders: Record<string, string> = {}
    try {
      const { useUserStore } = await import('@/store/modules/user')
      const userStore = useUserStore()
      if (userStore.accessToken) {
        authHeaders = { 'Authorization': userStore.accessToken }
      }
    } catch {
      // 获取 token 失败时不阻断下载
    }

    // 尝试方法一：使用 fetch 获取文件 Blob（适用于同源或已配置 CORS 的资源）
    const tryFetchDownload = async (): Promise<boolean> => {
      try {
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            'Accept': '*/*',
            ...authHeaders
          },
          credentials: 'include'
        })
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`)
        }
        
        const blob = await response.blob()
        const blobUrl = URL.createObjectURL(blob)
        
        const a = document.createElement('a')
        a.href = blobUrl
        a.download = fileName
        document.body.appendChild(a)
        a.click()
        
        document.body.removeChild(a)
        URL.revokeObjectURL(blobUrl)
        return true
      } catch (error) {
        console.warn('fetch 下载失败，尝试直接链接下载:', error)
        return false
      }
    }

    // 尝试方法二：直接使用 a 标签（适用于浏览器可直接访问的资源）
    const tryDirectDownload = (): boolean => {
      try {
        const a = document.createElement('a')
        a.href = url
        a.download = fileName
        a.target = '_blank'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        return true
      } catch (error) {
        console.error('直接下载失败:', error)
        return false
      }
    }

    // 尝试方法三：使用 XMLHttpRequest（某些情况下比 fetch 兼容性更好）
    const tryXhrDownload = (): Promise<boolean> => {
      return new Promise((resolve) => {
        const xhr = new XMLHttpRequest()
        xhr.open('GET', url, true)
        xhr.responseType = 'blob'
        xhr.withCredentials = true
        Object.entries(authHeaders).forEach(([key, value]) => {
          xhr.setRequestHeader(key, value)
        })
        
        xhr.onload = () => {
          if (xhr.status === 200) {
            try {
              const blob = xhr.response as Blob
              const blobUrl = URL.createObjectURL(blob)
              
              const a = document.createElement('a')
              a.href = blobUrl
              a.download = fileName
              document.body.appendChild(a)
              a.click()
              
              document.body.removeChild(a)
              URL.revokeObjectURL(blobUrl)
              resolve(true)
            } catch (error) {
              console.error('XHR 下载处理失败:', error)
              resolve(false)
            }
          } else {
            console.error(`XHR 下载失败: status ${xhr.status}`)
            resolve(false)
          }
        }
        
        xhr.onerror = () => {
          console.error('XHR 请求失败')
          resolve(false)
        }
        
        xhr.send()
      })
    }

    // 按顺序尝试各种下载方法
    if (await tryFetchDownload()) {
      return true
    }
    
    if (await tryXhrDownload()) {
      return true
    }
    
    if (tryDirectDownload()) {
      return true
    }
    console.error('所有下载方法均失败')
    return false
}