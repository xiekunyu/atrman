<template>
  <div class="am-file-upload">
    <div class="upload-mode-selector">
      <ElSegmented v-model="viewMode" :options="viewOptions" size="large" />
      <div class="upload-mode-selector-text">
        <ElButton type="danger" plain @click="cancelUpload">取消上传</ElButton>
      </div>
    </div>

    <ElRow v-if="storageList.length" :gutter="10" class="storage-list">
      <ElCol v-for="item in storageList" :key="item.id" :span="6">
        <div
          class="storage-item"
          :class="{ 'is-selected': isSelected(item), 'is-disabled': hasUploading }"
          @click="handleStorageClick(item)"
        >
          <div class="storage-item-main">
            <span class="storage-item-icon">
              <ArtSvgIcon icon="ri:hard-drive-3-line" />
            </span>
            <div class="storage-item-info">
              <div class="storage-item-name">{{ item.name }}</div>
              <div class="storage-item-desc">{{ item?.configInfo?.name || item.name }}</div>
            </div>
          </div>
        </div>
      </ElCol>
    </ElRow>
    <ElEmpty v-else description="暂无存储库" :image-size="72" />

    <template v-if="viewMode == 1">
      <el-upload
        ref="uploadRef"
        multiple
        :auto-upload="false"
        :on-change="handleFileChange"
        :on-remove="handleFileRemove"
        action="#"
        drag
        accept="*"
        class="upload-drag"
      >
        <div class="el-upload__text">
          <ArtSvgIcon icon="ri:upload-cloud-2-line" class="upload-icon" />
          <div class="upload-text">将文件拖到此处，或<em>点击上传</em></div>
        </div>
      </el-upload>

      <div class="file-list-header" v-if="fileList.length > 0">
        <div class="file-list-title">
          文件列表（共 {{ fileList.length }} 个，成功 {{ successCount }} 个，失败 {{ failedCount }} 个）
        </div>
        <div class="upload-actions">
          <el-button @click="clearFileList" :disabled="hasUploading">清空列表</el-button>
          <el-button v-if="hasFailed" @click="retryFailedUploads" :disabled="hasUploading">
            <ArtSvgIcon icon="ri:refresh-line" class="mr-1" />
            重试失败项
          </el-button>
          <el-button type="success" @click="startUpload" :disabled="fileList.length === 0 || hasUploading">
            <ArtSvgIcon icon="ri:upload-cloud-2-line" class="mr-1" />
            开始上传
          </el-button>
        </div>
      </div>

      <div v-if="fileList.length > 0" class="file-list-container">
        <div class="file-grid">
          <div v-for="file in fileList" :key="file.uid" class="file-item">
            <div class="file-preview">
              <img v-if="isImageFile(file.name)" :src="getFilePreviewUrl(file)" class="file-thumbnail" />
              <ArtSvgIcon v-else :icon="getFileIcon(file.name)" class="file-icon-large" />
              <div class="file-actions" v-if="!isActiveFile(file)">
                <span class="delete-icon" @click.stop="handleFileRemove(file)">
                  <ArtSvgIcon icon="ri:close-line" />
                </span>
              </div>
            </div>
            <div class="file-info">
              <div class="file-name" :title="file.name">{{ file.name }}</div>
              <div class="file-size">{{ formatFileSize(file.size) }}</div>
              <div class="file-status-badge">
                <template v-if="file.status === 'md5'">
                  <el-tag type="info" size="small" effect="light">计算标识</el-tag>
                </template>
                <template v-else-if="file.status === 'checking'">
                  <el-tag type="info" size="small" effect="light">检查文件</el-tag>
                </template>
                <template v-else-if="file.status === 'instant'">
                  <el-tag type="success" size="small" effect="light">秒传</el-tag>
                </template>
                <template v-else-if="file.status === 'success'">
                  <el-tag type="success" size="small" effect="light">已完成</el-tag>
                </template>
                <template v-else-if="file.status === 'error'">
                  <el-tag type="danger" size="small" effect="light">失败</el-tag>
                </template>
                <template v-else>
                  <el-tag size="small" effect="light">等待上传</el-tag>
                </template>
              </div>
              <div v-if="file.status === 'error'" class="file-error" :title="file.errorMessage">
                {{ file.errorMessage || '上传失败，请重试' }}
              </div>
              <div v-if="file.status === 'error'" class="file-retry">
                <el-button link type="primary" size="small" :disabled="hasUploading" @click="retryUpload(file)">
                  重试
                </el-button>
                <span v-if="file.retryCount" class="retry-count">已失败 {{ file.retryCount }} 次</span>
              </div>
            </div>
            <div v-if="file.status === 'uploading'" class="upload-progress-overlay">
              <el-progress :percentage="file.percent || 0" :stroke-width="6" :width="60" type="circle" />
            </div>
          </div>
        </div>
      </div>
    </template>
    <template v-else>
      <div class="url-upload-section">
        <div class="url-upload-input">
          <el-input
            v-model="urlInput"
            placeholder="请输入文件链接"
            clearable
            @keyup.enter="handleUrlUpload"
          />
          <el-button type="primary" @click="handleUrlUpload" :loading="urlUploading">
            下载并上传
          </el-button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getFileMd5, formatFileSize, getFileIcon } from '@/utils/file'
import fileApi from '@/api/system/file'
import storageApi from '@/api/system/storage'

type UploadStatus = 'ready' | 'md5' | 'checking' | 'instant' | 'uploading' | 'success' | 'error'

interface UploadFileItem {
  uid: string
  name: string
  size: number
  percent: number
  status: UploadStatus
  raw?: File
  url?: string
  md5?: string
  errorMessage?: string
  retryCount?: number
  previewUrl?: string
}

interface UploadSummary {
  total: number
  success: number
  failed: number
  instant: number
}

const fileList = ref<UploadFileItem[]>([])
const uploadRef = ref()
const urlInput = ref('')
const urlUploading = ref(false)
const viewOptions = ref([
  { label: '本地上传', value: 1 },
  { label: '网络链接上传', value: 2 }
])
const viewMode = ref(1)
const storageList = ref<any>([])
const currentStorage = ref<any>({})
const storageId = ref(1)

interface Props {
  pid: number | string
  storage_id?: number
}
const props = withDefaults(defineProps<Props>(), {
  pid: '',
  storage_id: 1
})

const activeStatuses: UploadStatus[] = ['uploading', 'md5', 'checking']

const hasUploading = computed(() => fileList.value.some((file) => isActiveFile(file)))
const failedFiles = computed(() => fileList.value.filter((file) => file.status === 'error'))
const hasFailed = computed(() => failedFiles.value.length > 0)
const readyFiles = computed(() => fileList.value.filter((file) => file.status === 'ready'))
const successCount = computed(
  () => fileList.value.filter((file) => ['success', 'instant'].includes(file.status)).length
)
const failedCount = computed(() => failedFiles.value.length)

const emit = defineEmits<{
  (e: 'cancel'): void
  (e: 'success'): void
  (e: 'complete', summary: UploadSummary): void
}>()

onMounted(() => {
  storageId.value = props.storage_id
  getStorageList()
})

onBeforeUnmount(() => {
  releaseAllPreviewUrls()
})

const getStorageList = async () => {
  storageList.value = await storageApi.list({})
}

const cancelUpload = () => {
  if (hasUploading.value) {
    ElMessage.warning('文件上传中，请稍后再取消')
    return
  }
  clearFileList()
  emit('cancel')
}

const isActiveFile = (file: UploadFileItem) => activeStatuses.includes(file.status)

const isImageFile = (filename: string): boolean => {
  const ext = filename.split('.').pop()?.toLowerCase() || ''
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico']
  return imageExts.includes(ext)
}

const getFilePreviewUrl = (file: UploadFileItem): string => {
  if (file.previewUrl) return file.previewUrl
  if (file.url) return file.url
  return ''
}

const createPreviewUrl = (file: File, filename: string) => {
  if (!isImageFile(filename)) return ''
  return URL.createObjectURL(file)
}

const releasePreviewUrl = (file: UploadFileItem) => {
  if (!file.previewUrl) return
  URL.revokeObjectURL(file.previewUrl)
  file.previewUrl = ''
}

const releaseAllPreviewUrls = () => {
  fileList.value.forEach(releasePreviewUrl)
}

const handleFileChange = (uploadFile: any) => {
  const existingUids = new Set(fileList.value.map((file) => file.uid))
  if (existingUids.has(uploadFile.uid)) return

  const raw = uploadFile.raw as File | undefined
  const newFile: UploadFileItem = {
    uid: uploadFile.uid,
    name: uploadFile.name,
    size: uploadFile.size,
    percent: 0,
    status: 'ready',
    raw,
    retryCount: 0,
    previewUrl: raw ? createPreviewUrl(raw, uploadFile.name) : ''
  }

  fileList.value = [...fileList.value, newFile]
}

const handleFileRemove = (file: any) => {
  const index = fileList.value.findIndex((item) => item.uid === file.uid)
  if (index === -1) return

  const target = fileList.value[index]
  if (isActiveFile(target)) {
    ElMessage.warning('文件上传中，暂不能删除')
    return
  }

  releasePreviewUrl(target)
  fileList.value.splice(index, 1)
}

const getErrorMessage = (error: any) =>
  error?.response?.data?.message ||
  error?.response?.data?.msg ||
  error?.message ||
  error?.msg ||
  '上传失败，请重试'

const createSummary = (): UploadSummary => ({
  total: 0,
  success: 0,
  failed: 0,
  instant: 0
})

const emitUploadResult = (summary: UploadSummary) => {
  if (summary.success > 0 || summary.instant > 0) {
    emit('success')
  }
  emit('complete', summary)
}

const checkMd5AndUpload = async (fileItem: UploadFileItem) => {
  if (!fileItem.raw) {
    fileItem.status = 'error'
    fileItem.errorMessage = '文件不存在，请重新选择'
    fileItem.retryCount = (fileItem.retryCount || 0) + 1
    return { success: false, instant: false }
  }

  try {
    fileItem.errorMessage = ''
    fileItem.percent = 0

    if (!fileItem.md5) {
      fileItem.status = 'md5'
      fileItem.md5 = await getFileMd5(fileItem.raw)
    }

    fileItem.status = 'checking'
    const checkRes = await fileApi.checkMd5({ md5: fileItem.md5 })

    if (checkRes.is_existed) {
      fileItem.status = 'instant'
      fileItem.percent = 100
      return { success: true, instant: true }
    }

    fileItem.status = 'uploading'
    await fileApi.uploadLocal(
      fileItem.raw,
      { pid: props.pid, storage_id: storageId.value },
      (percent: number) => {
        fileItem.percent = percent
      }
    )

    fileItem.status = 'success'
    fileItem.percent = 100
    return { success: true, instant: false }
  } catch (error: any) {
    fileItem.status = 'error'
    fileItem.errorMessage = getErrorMessage(error)
    fileItem.retryCount = (fileItem.retryCount || 0) + 1
    return { success: false, instant: false }
  }
}

const startUpload = async () => {
  const pendingFiles = readyFiles.value

  if (pendingFiles.length === 0) {
    if (hasFailed.value) {
      ElMessage.warning('没有待上传的文件，可点击重试失败项')
      return
    }
    if (fileList.value.length > 0 && successCount.value === fileList.value.length) {
      ElMessage.warning('文件已上传完成')
      return
    }
    ElMessage.warning('没有待上传的文件')
    return
  }

  const summary = createSummary()
  summary.total = pendingFiles.length

  for (const file of pendingFiles) {
    const result = await checkMd5AndUpload(file)
    if (result.success) summary.success += 1
    if (result.instant) summary.instant += 1
    if (!result.success) summary.failed += 1
  }
  emitUploadResult(summary)
}

const retryUpload = async (file: UploadFileItem) => {
  if (file.status !== 'error') return
  if (hasUploading.value) {
    ElMessage.warning('请等待当前上传完成后再重试')
    return
  }

  file.status = 'ready'
  file.percent = 0
  file.errorMessage = ''

  const summary = createSummary()
  summary.total = 1
  const result = await checkMd5AndUpload(file)
  if (result.success) summary.success = 1
  if (result.instant) summary.instant = 1
  if (!result.success) summary.failed = 1

  emitUploadResult(summary)
}

const retryFailedUploads = async () => {
  if (hasUploading.value) {
    ElMessage.warning('请等待当前上传完成后再重试')
    return
  }

  const retryFiles = [...failedFiles.value]
  if (!retryFiles.length) return

  const summary = createSummary()
  summary.total = retryFiles.length

  for (const file of retryFiles) {
    file.status = 'ready'
    file.percent = 0
    file.errorMessage = ''
    const result = await checkMd5AndUpload(file)
    if (result.success) summary.success += 1
    if (result.instant) summary.instant += 1
    if (!result.success) summary.failed += 1
  }
  emitUploadResult(summary)
}

const handleUrlUpload = async () => {
  if (!urlInput.value) {
    ElMessage.warning('请输入文件链接')
    return
  }

  const summary = createSummary()
  summary.total = 1
  urlUploading.value = true
  try {
    await fileApi.uploadUrl({ url: urlInput.value, pid: props.pid, storage_id: storageId.value })
    summary.success = 1
    ElMessage.success('上传成功')
    urlInput.value = ''
  } catch (error: any) {
    summary.failed = 1
    ElMessage.error(getErrorMessage(error))
  } finally {
    urlUploading.value = false
    emitUploadResult(summary)
  }
}

const clearFileList = () => {
  if (hasUploading.value) {
    ElMessage.warning('文件上传中，暂不能清空')
    return
  }
  releaseAllPreviewUrls()
  fileList.value = []
  uploadRef.value?.clearFiles?.()
}

const isSelected = (item: any) => storageId.value === item.id

const handleStorageClick = (item: any) => {
  if (hasUploading.value) {
    ElMessage.warning('文件上传中，暂不能切换存储')
    return
  }
  storageId.value = item.id
  currentStorage.value = item
}
</script>

<style scoped lang="scss">
.am-file-upload {
  .upload-mode-selector {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 15px;
  }

  .upload-drag {
    width: 100%;

    :deep(.el-upload) {
      width: 100%;
    }

    /* 直接隐藏 el-upload 自带的文件列表 */
    :deep(.el-upload-list) {
      display: none !important;
    }

    :deep(.el-upload-dragger) {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      height: 200px;
      background-color: var(--default-bg-color);
      border: 1px dashed var(--el-border-color-light);
      border-radius: 6px;

      &:hover {
        border-color: #409eff;
      }
    }
  }

  .upload-icon {
    margin-bottom: 10px;
    font-size: 40px;
    color: #c0c4cc;
  }

  .upload-text {
    font-size: 14px;
    color: #606266;

    em {
      font-style: normal;
      color: #409eff;
    }
  }

  .url-upload-section {
    padding: 15px;
    margin-top: 20px;
    background-color: var(--default-bg-color);
    border-radius: 6px;

    .url-upload-input {
      display: flex;
      gap: 10px;

      .el-input {
        flex: 1;
      }
    }
  }

  .file-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px;

    .file-list-title {
      display: flex;
      align-items: center;
      font-size: 14px;
      font-weight: 500;
      color: #303133;
    }
  }

  .file-list-container {
    max-height: 300px;
    overflow-y: auto;

    .file-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      gap: 12px;
      padding: 10px;
    }

    .file-item {
      position: relative;
      overflow: hidden;
      background-color: var(--color-bg-1);
      border: 1px solid var(--el-border-color-light);
      border-radius: 6px;
      transition: all 0.3s ease;

      &:hover {
        border-color: var(--color-primary);
        box-shadow: 0 4px 12px 0 rgb(0 0 0 / 15%);
      }

      .file-preview {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100px;
        overflow: hidden;
        background-color: var(--default-bg-color);

        .file-thumbnail {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .file-icon-large {
          font-size: 48px;
          color: var(--color-text-3);
        }

        .file-actions {
          position: absolute;
          top: 4px;
          right: 4px;
          visibility: hidden;
          opacity: 0;
          transition: all 0.3s ease;

          .delete-icon {
            display: inline-block;
            width: 24px;
            height: 24px;
            font-size: 16px;
            line-height: 21px;
            color: #fff;
            text-align: center;
            cursor: pointer;
            background-color: rgb(0 0 0 / 50%);
            border-radius: 50%;
            transition: all 0.3s ease;

            &:hover {
              color: #fff;
              background-color: #f56c6c;
            }
          }
        }
      }

      &:hover .file-actions {
        visibility: visible;
        opacity: 1;
      }

      /* 上传进度遮罩 */
      .upload-progress-overlay {
        position: absolute;
        inset: 0;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: rgb(255 255 255 / 90%);
        border-radius: 6px;
      }

      /* 状态标签 */
      .file-status-badge {
        position: absolute;
        top: 4px;
        left: 4px;
        z-index: 5;
      }

      .file-info {
        padding: 8px;

        .file-name {
          margin-bottom: 4px;
          overflow: hidden;
          font-size: 12px;
          line-height: 20px;
          color: var(--color-text-1);
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .file-size {
          margin-bottom: 4px;
          font-size: 11px;
          line-height: 20px;
          color: var(--color-text-2);
          text-align: center;
        }

        .file-error {
          margin-top: 4px;
          overflow: hidden;
          font-size: 11px;
          line-height: 16px;
          color: var(--el-color-danger);
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .file-retry {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 4px;
          margin-top: 2px;
        }

        .retry-count {
          font-size: 11px;
          color: var(--el-text-color-secondary);
        }
      }
    }
  }

  .upload-actions {
    display: flex;
    gap: 10px;
  }
}

.storage-list {
  row-gap: 10px;
  margin-bottom: 15px;
}

.storage-item {
  display: flex;
  align-items: center;
  height: 64px;
  padding: 10px 12px;
  color: var(--art-gray-800);
  cursor: pointer;
  background-color: var(--art-gray-100);
  border: 1px solid var(--art-gray-300);
  border-radius: 12px;
  transition: all 0.2s ease;

  &:hover:not(.is-disabled) {
    color: var(--el-color-primary);
    border-color: rgba(var(--el-color-primary-rgb), 0.35);
    box-shadow: 0 8px 18px rgb(0 0 0 / 8%);
    transform: translateY(-1px);
  }

  &.is-disabled {
    cursor: not-allowed;
    opacity: 0.65;
  }

  &.is-selected {
    color: var(--el-color-primary);
    background-color: rgba(var(--el-color-primary-rgb), 0.1);
    border-color: rgba(var(--el-color-primary-rgb), 0.5);
    box-shadow: inset 3px 0 0 var(--el-color-primary);
  }
}

.storage-item-main {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 8px;
}

.storage-item-icon {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  font-size: 16px;
  color: var(--el-color-primary);
  background-color: var(--default-box-color);
  border: 1px solid var(--art-gray-300);
  border-radius: 11px;
}

.storage-item-info {
  min-width: 0;
}

.storage-item-name,
.storage-item-desc {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.storage-item-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--art-gray-900);
}

.storage-item-desc {
  margin-top: 4px;
  font-size: 12px;
  color: var(--art-gray-600);
}
</style>
