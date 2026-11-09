<template>
  <ElDialog
    v-model="visible"
    :title="title"
    width="1000px"
    append-to-body
    align-center
    class="el-dialog-border"
    body-class="dialog-body-pd"
    @close="handleClose"
  >
    <ElContainer class="am-file-selection">
      <!-- 左侧目录树 -->
      <ElAside width="220px" class="file-tree-panel">
        <ElContainer>
            <ElHeader height="60px"   class="panel-header">
                <span>目录</span>
                <ElButton text type="primary" @click="handleRefreshTree" :icon="Refresh" />
            </ElHeader>
            <ElMain class="panel-main">
                <ElScrollbar height="50vh">
                    <ElTree 
                        class="folder-tree"
                        ref="treeRef"
                        :data="treeData"
                        :props="treeProps"
                        node-key="id"
                        :current-node-key="currentFolderId"
                        :expand-on-click-node="false"
                        :highlight-current="true"
                        :default-expanded-keys="defaultExpandedKeys"
                        @node-click="handleNodeClick"
                    >
                        <template #default="{ node, data }">
                        <span class="tree-node">
                            <ArtSvgIcon v-if="node.expanded" icon="ri:folder-open-fill" class="folder-icon" />
                            <ArtSvgIcon v-else icon="ri:folder-fill" class="folder-icon" />
                            <span class="node-label">{{ node.label }}</span>
                        </span>
                        </template>
                    </ElTree>
                </ElScrollbar>
            </ElMain>
        </ElContainer>
      </ElAside>

      <!-- 右侧文件列表 -->
      <ElMain class="file-list-panel" v-if="!uploadVisible">
        <ElContainer>
            <ElHeader height="60px"  class="panel-header">
                <!-- 工具栏 -->
                <div class="header-left">
                    <span>文件列表</span>
                    <span class="file-count">({{ total }}个文件)</span>
                </div>
                <div class="header-right flex items-center gap-2">
                    <!-- 上传按钮 -->
                    <ElButton type="primary" :icon="Upload" @click="handleOpenUpload" plain>
                        上传文件
                    </ElButton>
                    <!-- 视图切换 -->
                    <ElSegmented v-model="viewMode" :options="viewOptions"  size="large" />
                </div>
            </ElHeader>
            <ElMain class="panel-main">
                <!-- 文件选择区 -->
                <ElScrollbar height="50vh">
                <div v-if="fileList.length === 0">
                    <ElEmpty description="暂无文件" />
                </div>

                <!-- 平铺视图 -->
                <div v-else-if="viewMode === 'grid'" class="file-grid">
                    <div
                    v-for="file in fileList"
                    :key="file.id"
                    class="file-grid-item"
                    :class="{ 'is-selected': isSelected(file.id) }"
                    @click="handleSelectFile(file)"
                    >
                    <div class="file-preview">
                        <img v-if="file.file_type==1" :src="getFileUrl(file, true)" class="file-thumbnail" />
                        <ArtSvgIcon v-else :icon="getFileIcon(file.name)" class="file-icon" />
                    </div>
                    <div class="file-name" :title="file.name">{{ file.name }}</div>
                    <div v-if="multiple" class="file-checkbox">
                        <ElCheckbox :model-value="isSelected(file.id)" @click.stop />
                    </div>
                    <div v-else class="file-radio">
                        <ElRadio :model-value="selectedIds[0]" :label="file.id" @click.stop>{{ '' }}</ElRadio>
                    </div>
                    </div>
                </div>

                <!-- 列表视图 -->
                <div v-else class="file-list">
                    <ElTable
                    ref="tableRef"
                    :data="fileList"
                    @row-click="handleSelectFile"
                    :row-class-name="tableRowClassName"
                    highlight-current-row
                    :row-key="(row: any) => row.id"
                    >
                    <ElTableColumn v-if="multiple" width="50" align="center">
                        <template #default="{ row }">
                        <ElCheckbox
                            :model-value="isSelected(row.id)"
                            @click.stop
                            @change="() => handleSelectFile(row)"
                        />
                        </template>
                    </ElTableColumn>
                    <ElTableColumn v-else width="50" align="center">
                        <template #default="{ row }">
                        <ElRadio :model-value="selectedIds[0]" :label="row.id" @click.stop>{{ '' }}</ElRadio>
                        </template>
                    </ElTableColumn>
                    <ElTableColumn label="文件名" prop="name" min-width="200">
                        <template #default="{ row }">
                        <div class="file-cell">
                            <img v-if="row.file_type==1" :src="getFileUrl(row, true)" class="cell-thumbnail" />
                            <ArtSvgIcon v-else :icon="getFileIcon(row.name)" class="cell-icon" />
                            <span class="cell-name">{{ row.name }}</span>
                        </div>
                        </template>
                    </ElTableColumn>
                    <ElTableColumn label="大小" prop="size" width="100">
                        <template #default="{ row }">
                        {{ formatFileSize(row.size) }}
                        </template>
                    </ElTableColumn>
                    <ElTableColumn label="创建时间" prop="create_time" width="180" />
                    </ElTable>
                </div>
                </ElScrollbar>
            </ElMain>
            <!-- 分页 -->
            <ElFooter height="60px">
                <div class="pagination-wrapper">
                <ElPagination
                    v-model:current-page="currentPage"
                    v-model:page-size="pageSize"
                    :total="total"
                    :page-sizes="[20, 50, 100, 200]"
                    layout="total, sizes, prev, pager, next, jumper"
                    @size-change="handleSizeChange"
                    @current-change="handlePageChange"
                />
                </div>
            </ElFooter>
        </ElContainer>
      </ElMain>
      <ElMain class="file-list-panel" v-else>
        <div class="upload-container">
          <AmFileUpload
            ref="uploadRef"
            class=""
            :pid="currentFolderId"
            :selectable="true"
            :selected-ids="selectedIds"
            @cancel="cancelUploadClick"
            @success="loadFileList"
            @select="handleSelectFile"
          />
        </div>
      </ElMain>
    </ElContainer>
    <template #footer>
      <div class="flex justify-between">
        <div>
          <div class="flex items-center gap-2" v-if="selectedFiles.length > 0">
            <b>  已选择 {{ selectedFiles.length }} 个文件</b>
            <ElLink type="primary" @click="clearSelection"  underline="never">清除选择</ElLink>
          </div>
        </div>
        <div>
            <ElButton @click="handleClose">取消</ElButton>
            <ElButton type="primary" @click="handleConfirm">确定</ElButton>
        </div>
        
      </div>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Refresh, Upload } from '@element-plus/icons-vue'
import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'
import AmFileUpload from './upload/index.vue'
import fileApi from '@/api/system/file'
import { formatFileSize, getFileIcon, getFileUrl } from '@/utils/file'
import { useFileStore } from '@/store/modules/file'
const fileStore = useFileStore()

interface FileItem {
  id: number
  pid: number
  name: string
  url: string
  size: number
  create_time: string
  [key: string]: any
}

interface TreeNode {
  id: number
  pid: number
  name: string
  label: string
  children?: TreeNode[]
}

interface Props {
  modelValue: boolean
  title?: string
  multiple?: boolean
  defaultValue?: any | number
  displayData?: any
  accept?: string
  type?: number
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: false,
  title: '选择文件',
  multiple: false,
  accept: '',
  defaultValue: [],
  displayData: [],
  type: 1
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'confirm',value:any | number): void
  (e: 'cancel'): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

const treeData = ref<any>([])
const fileList = ref<any>([])
const selectedIds = ref<number[]>([])
const selectedFiles = ref<any[]>([])
const currentFolderId = ref<number|string>('')
const defaultExpandedKeys = ref<number[]>([0])
const currentPage = ref(1)
const pageSize = ref(20)
const total = ref(0)
const uploadVisible = ref(false)


const viewMode = ref<'grid' | 'list'>('grid')
const viewOptions = [
  { label: '平铺', value: 'grid' },
  { label: '列表', value: 'list' }
]

const treeProps = {
  children: 'children',
  label: 'name'
}

const isSelected = (id: number) => {
  return selectedIds.value.includes(id)
}

onMounted(() => {
  loadTree()
  loadFileList()
  selectedIds.value = props.defaultValue || []
  selectedFiles.value = props.displayData || []
})


const tableRowClassName = ({ row }: { row: FileItem }) => {
  return isSelected(row.id) ? 'selected-row' : ''
}

const loadTree = async () => {
  try {
    await fileStore.loadFolder()
    treeData.value = fileStore.folderList
    defaultExpandedKeys.value = [0]
  } catch (error) {
    console.error('加载目录树失败:', error)
  }
}

const loadFileList = async () => {
  try {
    const res = await fileApi.list({
      pid: currentFolderId.value,
      current: currentPage.value,
      size: pageSize.value,
      file_type: props.type || ''
    })
    fileList.value = res.data || []
    total.value = res.total || 0
  } catch (error) {
    console.error('加载文件列表失败:', error)
  }
}

const handleNodeClick = (data: TreeNode) => {
  currentFolderId.value = data.id
  currentPage.value = 1
  loadFileList()
}

const handleSelectFile = (file: FileItem) => {
  if (props.multiple) {
    const index = selectedIds.value.indexOf(file.id)
    if (index > -1) {
      selectedIds.value.splice(index, 1)
      selectedFiles.value=selectedFiles.value.filter((f: FileItem) => f.id !== file.id)
    } else {
      selectedFiles.value.push(file)
      selectedIds.value.push(file.id)
    }
  } else {
    selectedFiles.value=[file]
    selectedIds.value = [file.id]
  }
}

const handleRefreshTree = () => {
  currentFolderId.value = ''
  loadTree()
}

const handleOpenUpload = () => {
  uploadVisible.value = true
}

const cancelUploadClick = () => {
  uploadVisible.value = false
  handleSizeChange()
}

const handleSizeChange = () => {
  currentPage.value = 1
  loadFileList()
}

const handlePageChange = () => {
  loadFileList()
}

const handleClose = () => {
  selectedIds.value = []
  emit('cancel')
}

const clearSelection = () => {
  selectedIds.value = []
  selectedFiles.value = []
}

const handleConfirm = () => {
  emit('confirm', selectedFiles.value)
  visible.value = false
}

watch(visible, (val) => {
  if (val) {
    loadTree()
    loadFileList()
  }
})
</script>

<style scoped lang="scss">
.file-tree-panel {
  border-right: 1px solid var(--el-border-color-light);
  padding-right: 16px;

  .panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 0;
    font-weight: 500;
  }
  .panel-main {
    padding: 0 !important;
  }
  .tree-node {
    display: flex;
    align-items: center;
    gap: 8px;
    .folder-icon {
        color: #f9c42e;
        font-size: 16px;
    }
    .node-label {
      font-size: 13px;
    }
  }
}

.file-list-panel {
  padding: 0 0 0 16px;

  .panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 0;
    .header-left {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 500;

      .file-count {
        font-weight: 400;
        color: var(--el-text-color-secondary);
      }
    }
  }

  .upload-container{
    padding: 16px 0;
  }
  .panel-main {
    padding: 0 !important;
  }

  .file-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 16px;
    padding: 8px;

    .file-grid-item {
      position: relative;
      border: 1px solid var(--el-border-color-light);
      border-radius: 6px;
      padding: 8px;
      cursor: pointer;
      transition: all 0.3s;

      &:hover {
        border-color: var(--el-color-primary);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      }

      &.is-selected {
        border-color: var(--el-color-primary);
        background-color: var(--el-color-primary-light-9);
      }

      .file-preview {
        width: 100%;
        height: 80px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;

        .file-thumbnail {
          width: 100%;
          height: 100%;
          object-fit: cover;
          border-radius: 4px;
        }

        .file-icon {
          font-size: 48px;
          color: var(--el-text-color-secondary);
        }
      }

      .file-name {
        font-size: 12px;
        text-align: center;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .file-checkbox
      {
        position: absolute;
        top: 0;
        right: 6px;
      }
      .file-radio {
        position: absolute;
        top: 0;
        right: 0;
      }
    }
  }

  .file-list {
    .file-cell {
      display: flex;
      align-items: center;
      gap: 8px;

      .cell-thumbnail {
        width: 32px;
        height: 32px;
        object-fit: cover;
        border-radius: 4px;
      }

      .cell-icon {
        font-size: 24px;
        color: var(--el-text-color-secondary);
      }

      .cell-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }

  .pagination-wrapper {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }
}

:deep(.selected-row) {
  background-color: var(--el-color-primary-light-9) !important;
}

.folder-tree :deep(.el-tree-node__content) {
  height: 40px;
  line-height: 40px;
  border-radius: 0;
}
</style>
