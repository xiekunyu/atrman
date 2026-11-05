<!-- 用户管理页面 -->
<template>
  <div class="art-full-height">
    <div class="box-border flex gap-4 h-full max-md:block max-md:gap-0 max-md:h-auto">
      <div class="flex-shrink-0 w-80 h-full max-md:w-full max-md:h-auto max-md:mb-5">
        <ElCard class="file-storage art-card-xs">
          <template #header>
            <div class="storage-header flex-wrap gap-3 flex-cb">
              <div class="storage-title">
                <span class="storage-title-icon">
                  <ArtSvgIcon icon="ri:database-2-line" />
                </span>
                <div>
                  <b>存储库</b>
                  <div class="storage-subtitle">共 {{ storageList.length }} 个</div>
                </div>
              </div>
              <div class="am-header-actions flex flex-wrap">
                <ArtButtonTable type="view" @click="refreshStorageList" icon="ri:refresh-line" />
                <ArtButtonTable
                  v-auth="'system:storage:delete'"
                  type="delete"
                  @click="delStorage(currentStorage)"
                  v-if="currentStorage?.id && currentStorage?.is_sys != 1"
                />
                <ArtButtonTable
                  v-auth="'system:storage:update'"
                  type="edit"
                  @click="editStorage(currentStorage)"
                  v-if="currentStorage?.id"
                />
                <ArtButtonTable
                  v-auth="'system:storage:save'"
                  type="add"
                  @click="storageVisible = true"
                />
              </div>
            </div>
          </template>
          <div v-if="storageList.length" class="storage-list">
            <div
              v-for="item in storageList"
              :key="item.id"
              class="storage-item"
              :class="{ 'is-selected': isSelected(item) }"
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
          </div>
          <ElEmpty v-else description="暂无存储库" :image-size="72" />
        </ElCard>
        <StorageDialog
          v-model:visible="storageVisible"
          :type="storageType"
          :edit-data="storageData"
          @submit="handleStorageSubmit"
          :key="comKey"
        />
        <ElCard class="am-card-header flex flex-col flex-1 min-h-0 art-table-card" shadow="never">
          <template #header>
            <div class="flex justify-between items-center">
              <b>目录分类</b>
              <div class="flex flex-wrap am-header-actions">
                <ArtButtonTable type="view" icon="ri:refresh-line" @click="getFolderTree" />
                <ArtButtonTable v-auth="'system:file:save'" type="add" @click="addFolder('add')" />
              </div>
            </div>

            <div>
              <div class="text-sm text-gray-500 mt-2"
                >说明：目录只负责业务归类，不直接映射对象存储路径。</div
              >
              <div class="grid grid-cols-2 gap-3 mt-2">
                <AmStatCard :value="fileStore.folderTotal" label="目录数" />
                <AmStatCard :value="currentFolder.name || '所有文件'" label="当前目录" />
              </div>
            </div>
          </template>
          <ElTree
            :data="folderTree"
            node-key="id"
            :render-after-expand="false"
            :props="{ children: 'children', label: 'name' }"
            :default-expanded-keys="[0]"
            check-strictly
            clearable
            class="folder-tree"
            @node-click="handleNodeClick"
          >
            <template #default="{ data, node }">
              <div class="flex justify-between items-center folder-tree-node">
                <div>
                  <ArtSvgIcon v-if="node.expanded" icon="ri:folder-open-fill" class="folder-icon" />
                  <ArtSvgIcon v-else icon="ri:folder-fill" class="folder-icon" />
                  {{ data.name }}
                </div>
                <ElDropdown class="folder-node-more">
                  <ArtSvgIcon icon="ri:more-2-fill" />
                  <template #dropdown>
                    <ElDropdownMenu>
                      <ElDropdownItem v-if="data.id != 0">
                        <div
                          class="flex-c gap-2"
                          v-auth="'system:file:update'"
                          @click="editFolder(data)"
                        >
                          <ArtSvgIcon icon="ri:edit-line" />
                          <span>编辑文件夹</span>
                        </div>
                      </ElDropdownItem>
                      <ElDropdownItem>
                        <div
                          class="flex-c gap-2"
                          v-auth="'system:file:save'"
                          @click="addSubFolder(data)"
                        >
                          <ArtSvgIcon icon="ri:node-tree" />
                          <span>添加子文件夹</span>
                        </div>
                      </ElDropdownItem>
                      <ElDropdownItem>
                        <div
                          class="flex-c gap-2"
                          v-auth="'system:file:delete'"
                          @click="deleteFolder(data)"
                        >
                          <ArtSvgIcon icon="ri:delete-bin-5-line" class="text-red-500" />
                          <span class="text-red-500">删除文件夹</span>
                        </div>
                      </ElDropdownItem>
                    </ElDropdownMenu>
                  </template>
                </ElDropdown>
              </div>
            </template>
          </ElTree>
          <!-- 文件夹弹窗 -->
          <FileDialog
            v-model:visible="fileVisible"
            :type="fileType"
            :edit-data="fileData"
            :pid="pid"
            @submit="handleFileSubmit"
          />
        </ElCard>
      </div>

      <div class="flex flex-col flex-1 min-w-0">
        <ElCard
          class="flex flex-col flex-1 min-h-0 art-table-card"
          style="margin-top: 0px"
          shadow="never"
        >
          <div class="flex justify-between items-center">
            <b>文件列表</b>
            <div>
              <ElSpace wrap>
                <AmDictForm
                  width="120px"
                  dict="file_type"
                  type="select"
                  v-model="param.file_type"
                  clearable
                  placeholder="请选择类型"
                  @change="handleSearch"
                />
                <ElInput
                  v-model="param.keywords"
                  placeholder="请输入文件名称或路径"
                  clearable
                  class="w-40"
                  @keyup.enter="handleSearch"
                />
                <ElButton type="primary" @click="uploadDialogVisible = true">
                  <ArtSvgIcon icon="ri:upload-cloud-2-line" class="mr-1" />
                  上传文件
                </ElButton>
              </ElSpace>
            </div>
          </div>

          <!-- 上传弹窗 -->
          <ElDialog
            v-model="uploadDialogVisible"
            title="文件上传"
            width="800px"
            align-center
            @closed="handleUploadDialogClosed"
            body-class="dialog-body-pd"
          >
            <AmFileUpload
              ref="amFileUploadRef"
              @cancel="uploadDialogVisible = false"
              @success="refreshData"
              :pid="currentFolder.id"
              :storage_id="currentStorage?.id"
            />
            <template #footer>
              <ElButton @click="uploadDialogVisible = false">关闭</ElButton>
            </template>
          </ElDialog>

          <!-- 表格 -->
          <ArtTable
            :data="data"
            :columns="columns"
            @selection-change="handleSelectionChange"
            show-overflow-tooltip
            :pagination="pagination"
            @sort-change="handleSortChange"
            @pagination:size-change="handleSizeChange"
            @pagination:current-change="handleCurrentChange"
            @row-dblclick="showFileInfo"
          >
            <template #operation="{ row }">
              <div class="flex">
                <ArtButtonTable
                  v-auth="'system:file:read'"
                  type="view"
                  @click="showFileInfo(row)"
                />
                <ArtButtonTable type="edit" icon="ri:link" @click="copyUrl(row)" />
                <ArtButtonTable
                  v-if="row.is_lock == 0"
                  type="delete"
                  v-auth="'system:file:delete'"
                  @click="deleteRow(row, fileApi.delete, refreshData)"
                />
              </div>
            </template>
          </ArtTable>

          <!-- 文件详情弹窗 -->
          <FileInfo v-model:visible="fileInfoVisible" :fileInfo="fileInfo" :title="fileTitle" />
        </ElCard>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import FileDialog from './modules/file-dialog.vue'
  import StorageDialog from './modules/storage-dialog.vue'
  import FileInfo from './modules/file-info.vue'
  import AmFileUpload from '@/components/business/am-file-selection/upload/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import fileApi from '@/api/system/file'
  import storageApi from '@/api/system/storage'
  import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'
  import AmStatCard from '@/components/business/am-stat-card/index.vue'
  import AmDictForm from '@/components/business/am-dict/form/index.vue'
  import { formatFileSize, getFileUrl } from '@/utils/file'
  import { useFileStore } from '@/store/modules/file'
  import { useRemoteDataStore } from '@/store/modules/remoteData'
  import { ElImage, ElMessageBox, ElMessage } from 'element-plus'

  const fileStore = useFileStore()
  const remoteDataStore = useRemoteDataStore()
  const folderTree = ref<any>([])
  const pid = ref(0)
  const fileVisible = ref(false)
  const fileType = ref('')
  const fileData = ref<any>({})
  const storageList = ref<any>([])
  const currentFolder = ref<any>([])
  const currentStorage = ref<any>({})
  const storageVisible = ref(false)
  const storageType = ref('add')
  const storageData = ref<any>({})
  const comKey = ref(0)
  const fileInfoVisible = ref(false)
  const fileInfo = ref<any>({})
  const fileTitle = ref('')
  const param = ref<any>({
    file_type: '',
    keywords: ''
  })

  const isSelected = (item: any) => {
    return currentStorage.value.id === item.id
  }

  // 上传弹窗相关
  const uploadDialogVisible = ref(false)

  // 编辑框
  const { handleSelectionChange, deleteRow } = tableOperation()

  onMounted(() => {
    getFolderTree()
    getStorageList()
  })

  const showFileInfo = (item: any) => {
    fileInfo.value = item
    fileInfoVisible.value = true
    fileTitle.value = item.name || '文件详情'
  }

  const getStorageList = async () => {
    storageList.value = await storageApi.list({})
  }

  const handleStorageSubmit = async () => {
    // 存储库增改后失效「储存库」远程源快照，配置页等引用处才能立刻取到新数据
    remoteDataStore.clearCacheByCode('storage_list')
    getStorageList()
  }

  const editStorage = (item: any) => {
    storageType.value = 'edit'
    storageData.value = item
    storageVisible.value = true
  }

  const copyUrl = (item: any) => {
    navigator.clipboard.writeText(getFileUrl(item, false))
    ElMessage.success('复制成功')
  }

  const handleStorageClick = (item: any) => {
    currentStorage.value = item
    Object.assign(searchParams, { storage_id: item.id })
    getData()
  }

  const refreshStorageList = () => {
    currentStorage.value = {}
    remoteDataStore.clearCacheByCode('storage_list')
    getStorageList()
    refreshData()
  }

  const delStorage = async (item: any) => {
    ElMessageBox.confirm('删除储存库将无法正常读取文件，确认删除吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(async () => {
      await storageApi.delete({ id: item.id })
      remoteDataStore.clearCacheByCode('storage_list')
      getStorageList()
    })
  }

  const getFolderTree = async () => {
    await fileStore.loadFolder()
    folderTree.value = fileStore.folderList
    currentFolder.value = {}
    Object.assign(searchParams, { pid: '' })
    refreshData()
  }

  const {
    columns,
    data,
    pagination,
    searchParams,
    refreshData,
    getData,
    handleSortChange,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: fileApi.list,
      columnsFactory: () => [
        { prop: 'id', label: 'ID', width: 80 },
        {
          prop: 'name',
          label: '文件名称',
          formatter: (row: any) => {
            return h('div', { class: 'flex items-center gap-3  flex-nowrap' }, [
              ['jpg', 'jpeg', 'png', 'gif'].includes(row.ext)
                ? h(ElImage, {
                    class: 'shrink-0 size-9.5 rounded-md table-row-image',
                    fit: 'cover',
                    src: getFileUrl(row, true),
                    previewSrcList: [getFileUrl(row, false)],
                    previewTeleported: true
                  })
                : h(ArtSvgIcon, {
                    icon: 'ri:file-text-line',
                    class: 'size-9 rounded-md table-row-image'
                  }),
              h('div', { class: 'ml-2 truncate' }, row.name)
            ])
          }
        },
        {
          prop: 'file_type',
          label: '类别',
          width: 85,
          sortable: true,
          amType: 'dict',
          dict: 'file_type'
        },
        {
          prop: 'size',
          label: '文件大小',
          width: 150,
          formatter: (row: any) => {
            return formatFileSize(row.size)
          }
        },
        { prop: 'create_time', label: '创建时间', width: 180 },
        { prop: 'operation', label: '操作', width: 150, useSlot: true }
      ]
    }
  })

  // 上传弹窗关闭时的处理
  const handleUploadDialogClosed = () => {
    refreshData()
  }

  const handleSearch = () => {
    Object.assign(searchParams, param.value)
    getData()
  }

  const addFolder = async (type: string) => {
    pid.value = 0
    fileVisible.value = true
    fileType.value = type
    fileData.value = {}
  }

  const editFolder = async (data: any) => {
    pid.value = data.pid
    fileVisible.value = true
    fileType.value = 'edit'
    fileData.value = data
  }

  const addSubFolder = async (data: any) => {
    pid.value = data.id
    fileVisible.value = true
    fileType.value = 'add'
    fileData.value = {}
  }

  const deleteFolder = async (data: any) => {
    fileApi.delete({ id: data.id }).then(() => {
      ElMessage.success('删除成功文件夹')
      getFolderTree()
    })
  }

  const handleFileSubmit = async () => {
    getFolderTree()
    fileVisible.value = false
  }

  const handleNodeClick = (data: any) => {
    currentFolder.value = data
    pid.value = data.id
    Object.assign(searchParams, { pid: data.id })
    getData()
  }
</script>
<style lang="scss" scoped>
  .folder-icon {
    color: #f9c42e;
    font-size: 16px;
  }

  .folder-tree-node {
    width: 100%;
    height: 40px;

    .folder-node-more {
      display: none;
      padding-right: 10px;
    }

    &:hover .folder-node-more {
      display: block;
      border: none;
    }
  }

  .folder-tree :deep(.el-tree-node__content) {
    height: 40px;
    line-height: 40px;
    border-radius: 0;
  }

  .main-card :deep(.el-card__body) {
    flex: 1;
    min-height: 0;
    padding: 0;
  }

  .main-card :deep(.el-card__header) {
    padding: 10px;
  }

  .file-storage {
    margin-bottom: 16px;
    overflow: hidden;

    :deep(.el-card__header) {
      padding: 12px 14px;
      border-bottom: 1px solid var(--art-gray-300);
    }

    :deep(.el-card__body) {
      padding: 12px;
    }

    .storage-header,
    .storage-title,
    .storage-item-main {
      display: flex;
      align-items: center;
    }

    .storage-title {
      gap: 10px;
      color: var(--art-gray-900);
    }

    .storage-title-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      font-size: 18px;
      color: var(--el-color-primary);
      background-color: var(--el-color-primary-light-9);
      border-radius: 10px;
    }

    .storage-subtitle {
      margin-top: 3px;
      font-size: 12px;
      font-weight: 400;
      color: var(--art-gray-600);
    }
    .storage-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .storage-item {
      display: flex;
      flex-direction: column;
      align-items: stretch;
      justify-content: space-between;
      gap: 10px;
      padding: 12px;
      color: var(--art-gray-800);
      background-color: var(--art-gray-100);
      border: 1px solid var(--art-gray-300);
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s ease;

      &:hover {
        color: var(--el-color-primary);
        background-color: var(--el-color-primary-light-9);
        border-color: var(--el-color-primary-light-5);
        transform: translateY(-1px);
      }
    }

    .storage-item-main {
      min-width: 0;
      gap: 8px;
    }

    .storage-item-icon {
      flex-shrink: 0;
      display: flex;
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
  }

  /* 文件上传进度样式 */
  .upload-progress-container {
    padding: 16px;
    margin-bottom: 16px;
    background-color: #f5f7fa;
    border-radius: 8px;
    border: 1px solid #e4e7ed;

    .upload-file-info {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;

      .upload-file-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 14px;
        color: #303133;
      }
    }

    .upload-status-text {
      margin-top: 8px;
      font-size: 12px;
      color: #909399;
      text-align: center;
    }
  }

  .hidden {
    display: none !important;
  }

  .storage-item.is-selected {
    color: var(--el-color-primary);
    background-color: var(--el-color-primary-light-9);
    border-color: var(--el-color-primary-light-5);
  }

  .image-row {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border: 1px solid #e4e7ed;
  }
</style>

<style>
  .table-row-image {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border: 1px solid #e4e7ed;
  }
</style>
