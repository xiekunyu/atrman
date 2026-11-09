<template>
  <div class="art-full-height">
    <!-- 搜索栏 -->
    <ArtSearchBar v-model="searchForm" :items="searchItems" @search="handleSearch" @reset="resetSearchParams" />

    <ElCard class="flex flex-col flex-1 min-h-0 art-table-card" shadow="never">
      <!-- 表格头部 -->
      <ArtTableHeader
        v-model:columns="columnChecks"
        :loading="loading"
        @refresh="refreshData"
      >
        <template #left>
          <ElSpace wrap>
            <ElButton type="primary" @click="handleCreate" :loading="creating" v-auth="'system:backup:create'">
              创建备份
            </ElButton>
            <ElButton @click="showUploadDialog" v-auth="'system:backup:upload'">
              上传备份
            </ElButton>
            <ElButton @click="handleBatchDelete" type="danger" :disabled="selectedRows.length === 0" v-auth="'system:backup:batchDelete'">
              批量删除
            </ElButton>
          </ElSpace>
        </template>
      </ArtTableHeader>

      <!-- 表格 -->
      <ArtTable
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @selection-change="handleSelectionChange"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #backup_type="{ row }">
          <ElTag :type="row.backup_type === 2 ? 'primary' : 'success'" size="small">
            {{ row.backup_type === 2 ? '系统备份' : '插件备份' }}
          </ElTag>
        </template>
        <template #status="{ row }">
          <ElTag :type="row.status === 1 ? 'success' : 'danger'" size="small">
            {{ row.status === 1 ? '成功' : '失败' }}
          </ElTag>
        </template>
        <template #file_count="{ row }">
          <span>{{ row.file_count || '-' }}</span>
        </template>
        <template #operation="{ row }">
          <ArtButtonTable v-auth="'system:backup:download'" type="view" icon="ri:download-line" tip="下载" @click="handleDownload(row)" />
          <ArtButtonTable v-auth="'system:backup:restore'" type="edit" icon="ri:restart-line" tip="恢复" @click="handleRestore(row)" />
          <ArtButtonTable v-auth="'system:backup:read'" type="view" tip="详情" @click="showDetail(row)" />
          <ArtButtonTable v-auth="'system:backup:delete'" type="delete" @click="handleDelete(row)" />
        </template>
      </ArtTable>
    </ElCard>

    <!-- 详情弹窗 -->
    <ElDialog v-model="detailVisible" title="备份详情" width="700px">
      <ElDescriptions :column="2" border label-width="100px" v-if="detailData">
        <ElDescriptionsItem label="备份名称">{{ detailData.title }}</ElDescriptionsItem>
        <ElDescriptionsItem label="版本号">{{ detailData.version }}</ElDescriptionsItem>
        <ElDescriptionsItem label="状态">
          <ElTag :type="detailData.status === 1 ? 'success' : 'danger'" size="small">
            {{ detailData.status === 1 ? '成功' : '失败' }}
          </ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="文件数量">{{ detailData.file_count }}</ElDescriptionsItem>
        <ElDescriptionsItem label="创建时间" :span="2">{{ detailData.create_time }}</ElDescriptionsItem>
        <ElDescriptionsItem label="数据库摘要" :span="2">
          <div v-if="detailData.database_summary" class="max-h-40 overflow-auto">
            <ElTag v-for="table in detailData.database_summary" :key="table.table" size="small" class="mr-1 mb-1">
              {{ table.table }} ({{ table.rows }}行)
            </ElTag>
          </div>
          <span v-else>-</span>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="插件列表" :span="2">
          <ElTag v-for="plugin in detailData.plugin_snapshot" :key="plugin" size="small" class="mr-1 mb-1">
            {{ plugin }}
          </ElTag>
          <span v-if="!detailData.plugin_snapshot?.length">-</span>
        </ElDescriptionsItem>
      </ElDescriptions>
    </ElDialog>

    <!-- 上传弹窗 -->
    <ElDialog v-model="uploadVisible" title="上传备份文件" width="500px">
      <ElUpload
        ref="uploadRef"
        drag
        :auto-upload="false"
        :limit="1"
        accept=".zip"
        :on-change="handleFileChange"
        :on-exceed="handleUploadExceed"
      >
        <ArtSvgIcon icon="ri:upload-cloud-2-line" class="upload-icon text-4xl" />
        <div class="el-upload__text">将文件拖到此处，或<em>点击上传</em></div>
        <template #tip>
          <div class="el-upload__tip">仅支持 .zip 格式的备份文件</div>
        </template>
      </ElUpload>
      <template #footer>
        <ElButton @click="uploadVisible = false">取消</ElButton>
        <ElButton type="primary" @click="handleUpload" :loading="uploading">上传</ElButton>
      </template>
    </ElDialog>

    <!-- 恢复确认弹窗 -->
    <ElDialog v-model="restoreVisible" title="系统恢复确认" width="600px">
      <ElAlert type="warning" :closable="false" class="mb-4">
        <template #title>
          恢复操作将覆盖当前系统文件和数据库，请确认已了解以下信息：
        </template>
      </ElAlert>
      <div v-if="checkResult">
        <ElDescriptions :column="2" border class="mb-4">
          <ElDescriptionsItem label="备份版本">{{ checkResult.backup_version }}</ElDescriptionsItem>
          <ElDescriptionsItem label="当前版本">{{ checkResult.current_version }}</ElDescriptionsItem>
          <ElDescriptionsItem label="备份 PHP">{{ checkResult.backup_php }}</ElDescriptionsItem>
          <ElDescriptionsItem label="当前 PHP">{{ checkResult.current_php }}</ElDescriptionsItem>
        </ElDescriptions>
        <div v-if="checkResult.warnings?.length" class="mb-4">
          <ElAlert type="info" :closable="false" title="警告信息">
            <ul class="list-disc pl-4 m-0">
              <li v-for="w in checkResult.warnings" :key="w">{{ w }}</li>
            </ul>
          </ElAlert>
        </div>
        <div v-if="checkResult.errors?.length">
          <ElAlert type="error" :closable="false" title="错误信息">
            <ul class="list-disc pl-4 m-0">
              <li v-for="e in checkResult.errors" :key="e">{{ e }}</li>
            </ul>
          </ElAlert>
        </div>
      </div>
      <template #footer>
        <ElButton @click="restoreVisible = false">取消</ElButton>
        <ElButton type="danger" @click="confirmRestore" :loading="restoring" :disabled="!checkResult?.compatible">
          确认恢复
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import { tableOperation } from '@/mixins/tableOperation'
  import backupApi from '@/api/system/backup'
  import { useUserStore } from '@/store/modules/user'
  import { getApiBaseURL } from '@/utils/env'
  import { ElMessage, ElMessageBox } from 'element-plus'

  defineOptions({ name: 'SystemBackup' })

  const userStore = useUserStore()

  const { handleSelectionChange, selectedRows } = tableOperation()

  const searchForm = ref({
    keywords: '',
    status: ''
  })

  const searchItems = ref([
    { label: '关键词', key: 'keywords', type: 'input', props: { placeholder: '名称/版本', clearable: true } },
    { label: '备份类型', key: 'backup_type', type: 'select', props: { placeholder: '全部', clearable: true, options: [
      { label: '插件备份', value: '1' },
      { label: '系统备份', value: '2' }
    ]}}
  ])

  const creating = ref(false)
  const detailVisible = ref(false)
  const detailData = ref<Record<string, any>>({})
  const uploadVisible = ref(false)
  const uploading = ref(false)
  const uploadRef = ref()
  const uploadFile = ref<File | null>(null)
  const restoreVisible = ref(false)
  const restoring = ref(false)
  const checkResult = ref<Record<string, any> | null>(null)
  const currentRestoreId = ref<number>(0)

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    getData,
    searchParams,
    resetSearchParams,
    handleSizeChange,
    handleCurrentChange,
    refreshData
  } = useTable({
    performance: { debounceTime: 500 },
    core: {
      apiFn: backupApi.list,
      apiParams: {
        current: 1,
        size: 20,
        ...searchForm.value
      },
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'id', label: 'ID', width: 80 },
        { prop: 'title', label: '备份名称', minWidth: 180, showOverflowTooltip: true },
        { prop: 'backup_type', label: '备份类型', width: 110, useSlot: true },
        { prop: 'version', label: '版本', width: 100 },
        { prop: 'file_count', label: '文件数', width: 90, useSlot: true },
        { prop: 'status', label: '状态', width: 90, useSlot: true },
        { prop: 'create_time', label: '创建时间', width: 180, sortable: true },
        { prop: 'operation', label: '操作', width: 200, fixed: 'right', useSlot: true }
      ]
    }
  })

  // 创建备份
  const handleCreate = async () => {
    try {
      await ElMessageBox.confirm('创建备份可能需要较长时间，确定要继续吗？', '提示', { type: 'info' })
      creating.value = true
      await backupApi.create()
      refreshData()
    } catch (e: any) {
      if (e !== 'cancel') {
        ElMessage.error(e?.message || '创建失败')
      }
    } finally {
      creating.value = false
    }
  }

  // 下载备份（参照插件下载的工作模式）
  const handleDownload = async (row: Record<string, any>) => {
    const response = await fetch(`${getApiBaseURL()}/sys/backup/download?id=${row.id}`, {
      method: 'GET',
      credentials: import.meta.env.VITE_WITH_CREDENTIALS === 'true' ? 'include' : 'same-origin',
      headers: {
        'Accept': '*/*',
        ...(userStore.accessToken ? { Authorization: userStore.accessToken } : {})
      }
    })

    const contentType = response.headers.get('Content-Type') || ''
    if (!response.ok || contentType.includes('application/json')) {
      const result = await response.json().catch(() => null)
      ElMessage.error(result?.msg || result?.message || '下载失败')
      return
    }

    const blob = await response.blob()
    const disposition = response.headers.get('Content-Disposition') || ''
    const fileName = disposition.match(/filename="?([^";]+)"?/)?.[1] || row.file?.name || `backup_${row.id}.zip`
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = fileName
    link.click()
    URL.revokeObjectURL(url)
  }

  // 删除备份
  const handleDelete = async (row: Record<string, any>) => {
    try {
      await ElMessageBox.confirm('确定要删除该备份吗？', '提示', { type: 'warning' })
      await backupApi.delete({ id: row.id })
      ElMessage.success('删除成功')
      refreshData()
    } catch (e: any) {
      if (e !== 'cancel') {
        ElMessage.error(e?.message || '删除失败')
      }
    }
  }

  // 批量删除
  const handleBatchDelete = async () => {
    try {
      await ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个备份吗？`, '提示', { type: 'warning' })
      await backupApi.batchDelete({ ids: selectedRows.value.map((r: any) => r.id) })
      ElMessage.success('批量删除成功')
      refreshData()
    } catch (e: any) {
      if (e !== 'cancel') {
        ElMessage.error(e?.message || '删除失败')
      }
    }
  }

  // 查看详情
  const showDetail = async (row: Record<string, any>) => {
    const res = await backupApi.read(row.id)
    detailData.value = res as Record<string, any>
    detailVisible.value = true
  }

  // 上传
  const showUploadDialog = () => {
    uploadFile.value = null
    uploadVisible.value = true
  }

  const handleFileChange = (file: any) => {
    uploadFile.value = file.raw
  }

  const handleUploadExceed = () => {
    ElMessage.warning('只能上传一个文件')
  }

  const handleUpload = async () => {
    if (!uploadFile.value) {
      ElMessage.warning('请选择备份文件')
      return
    }
    try {
      uploading.value = true
      const formData = new FormData()
      formData.append('file', uploadFile.value)
      await backupApi.upload(formData)
      ElMessage.success('上传成功')
      uploadVisible.value = false
      refreshData()
    } catch (e: any) {
      ElMessage.error(e?.message || '上传失败')
    } finally {
      uploading.value = false
    }
  }

  // 恢复
  const handleRestore = async (row: Record<string, any>) => {
    try {
      currentRestoreId.value = row.id
      const res = await backupApi.check({ id: row.id })
      checkResult.value = res as Record<string, any>
      restoreVisible.value = true
    } catch (e: any) {
      ElMessage.error(e?.message || '检查失败')
    }
  }

  const confirmRestore = async () => {
    try {
      await ElMessageBox.confirm(
        '恢复操作将覆盖当前系统，确定要继续吗？恢复后需要重启服务。',
        '最终确认',
        { type: 'warning', confirmButtonText: '确认恢复', cancelButtonText: '取消' }
      )
      restoring.value = true
      const res = await backupApi.restore({ id: currentRestoreId.value })
      ElMessage.success((res as any)?.message || '恢复成功，请重启服务')
      restoreVisible.value = false
    } catch (e: any) {
      if (e !== 'cancel') {
        ElMessage.error(e?.message || '恢复失败')
      }
    } finally {
      restoring.value = false
    }
  }

  const handleSearch = (params: Record<string, any>) => {
    Object.assign(searchParams, params)
    getData()
  }
</script>
