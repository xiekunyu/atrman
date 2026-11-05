<template>
  <ElContainer class="config-edit-layout">
    <ElAside class="config-edit-aside" width="400px">
      <div class="config-panel config-category-panel">
        <div class="config-category-header">
          <div class="config-category-title">
            <b>配置分类</b>
          </div>
          <div class="am-header-actions" v-if="mode === 'edit'">
            <ArtButtonTable type="view" icon="ri:refresh-line" @click="refresh" />
            <ArtButtonTable
              v-if="selectedRow.id && selectedRow.is_sys != 1"
              v-auth="'system:config:update'"
              type="edit"
              icon="ri:edit-line"
              @click="showDialog('edit', selectedRow)"
            />
            <ArtButtonTable
              v-if="selectedRow.id && selectedRow.is_sys != 1"
              v-auth="'system:config:delete'"
              type="delete"
              icon="ri:delete-bin-line"
              @click="deleteRow(selectedRow, configApi.delete)"
            />
            <ArtButtonTable
              v-auth="'system:config:save'"
              type="add"
              icon="ri:add-line"
              @click="addData('add')"
            />
          </div>
        </div>
        <!-- 表格 -->
        <ArtTable
          :loading="loading"
          :data="data"
          :columns="columns"
          height="100%"
          empty-height="100%"
          highlight-current-row
          class="am-table-body"
          @selection-change="handleSelectionChange"
          @current-change="rowChange"
        />
      </div>
    </ElAside>

    <ElMain class="config-edit-main">
      <div class="config-panel config-main-panel">
        <div class="config-main-header">
          <div class="config-main-title">
            <b>{{ selectedRow?.name || '配置项' }}</b>
            <span v-if="selectedRow?.code">{{ selectedRow.code }}</span>
          </div>
          <div v-if="selectedId !== 0" class="card-header-actions">
            <ArtButtonTable
              v-auth="'system:config:setConfig'"
              type="add"
              icon="ri:settings-3-line"
              v-if="mode === 'edit'"
              @click="openFormBuilder"
            />
          </div>
        </div>
        <!-- 表单 -->
        <div class="config-form-body">
          <el-empty v-if="selectedId === 0" description="请先选择左侧配置" />
          <template v-else>
            <AmForm
              ref="formRef"
              v-model="formData"
              :items="formConfig.items"
              :rules="formConfig.rules"
              :span="24"
              :gutter="20"
              label-width="150px"
              :show-submit="formConfig.items?.length > 0"
              :show-reset="false"
              @submit="saveConfig"
            />

            <div v-if="formConfig.items?.length == 0" class="config-empty">
              <el-empty description="暂无配置项" />
            </div>
          </template>
        </div>
      </div>
    </ElMain>
    <!-- 弹窗 -->
    <EditDialog
      v-model:visible="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      :pid="selectedId"
      :appName="app_name"
      @submit="handleDialogSubmit"
    />
    <AmFormBuilder
      v-model:visible="formDialogVisible"
      title="配置修改"
      :items="formItems"
      :key="comKey"
      @submit="setConfig"
    />
  </ElContainer>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import configApi from '@/api/system/config'
  import EditDialog from './edit-dialog.vue'
  import AmFormBuilder from '@/components/business/am-form-builder/index.vue'
  import { buildFormConfig } from '@/utils/custom/index'

  defineOptions({ name: 'Config' })
  const selectedId = ref(0)
  const selectedRow = ref<any>({})
  const activeTab = ref<any>({})
  const appList = ref<any>([])
  const formRef = ref()
  const formItems = ref<any>([])
  const formDialogVisible = ref(false)
  const formData = ref<any>({})
  const formConfig = ref<any>({ items: [], rules: {} })
  const configList = ref<any>([])
  const comKey = ref(0)

  onMounted(() => {
    activeTab.value = { label: '系统配置', name: 'system' }
    appList.value = [
      { label: '系统配置', name: 'system' },
      { label: '存储配置', name: 'storage' },
      { label: '短信配置', name: 'sms_provider' }
    ]
  })

  const props = defineProps({
    app_name: {
      type: String,
      default: 'system'
    },
    mode: {
      type: String,
      default: 'edit'
    }
  })

  const { dialogType, dialogVisible, dialogData, showDialog, handleSelectionChange, deleteRow } =
    tableOperation()

  /**
   * 添加配置
   */
  const addData = (type: any) => {
    selectedId.value = 0
    showDialog(type)
  }

  const refresh = () => {
    selectedId.value = 0
    selectedRow.value = {}
    refreshData()
  }

  const { columns, data, loading, searchParams, refreshData } = useTable({
    // 核心配置
    core: {
      apiFn: configApi.list,
      apiParams: {
        app_name: props.app_name
      },
      columnsFactory: () => [
        { prop: 'name', label: '配置名称', width: 120 },
        { prop: 'code', label: '配置编码' },
        { prop: 'is_sys', label: '数据类型', width: 80, amType: 'dict', dict: 'is_sys' }
      ]
    }
  })

  /**
   * 处理弹窗提交事件
   */
  const handleDialogSubmit = async () => {
    try {
      dialogVisible.value = false
      dialogData.value = {}
      setTimeout(() => {
        refreshData()
      }, 500)
    } catch (error) {
      console.error('提交失败:', error)
    }
  }

  const rowChange = async (row: any) => {
    if (!row) return
    selectedId.value = row.id
    selectedRow.value = row
    loadConfig(row)
  }

  const loadConfig = async (row: any) => {
    const items = [] as any[]
    const config = {} as any
    try {
      const res = await configApi.getConfig({ id: row.id })
      configList.value = res.list || []

      if (configList.value.length > 0) {
        configList.value.forEach((item: any) => {
          const options = item?.options || []
          Object.assign(options, {
            id: item.id,
            is_sys: item.is_sys,
            displayData: item.displayData || []
          })
          items.push(options)
          config[item.code] = item.value
        })
      }
      // 先更新 formItems，再基于新的 formItems 构建 formConfig
      formItems.value = items
      formConfig.value = buildFormConfig(formItems.value)
      formData.value = config
    } catch (error) {
      console.error('获取配置异常:', error)
    }
  }

  const openFormBuilder = () => {
    comKey.value++
    formDialogVisible.value = true
  }

  const setConfig = (items: any) => {
    const data = {
      id: selectedId.value,
      items: items
    }
    configApi.setConfig(data)
    setTimeout(() => {
      loadConfig(selectedRow.value)
    }, 500)
  }

  const saveConfig = async () => {
    if (!formRef.value) return

    try {
      await formRef.value.validate()
      const data = {
        id: selectedId.value,
        params: formData.value
      }
      configApi.saveConfig(data)
      setTimeout(() => {
        loadConfig(selectedRow.value)
      }, 500)
    } catch (error) {
      console.error('表单验证失败:', error)
    }
  }

  watch(
    () => props.app_name,
    (newVal) => {
      selectedId.value = 0
      selectedRow.value = {}
      Object.assign(searchParams, { app_name: newVal })
      refreshData()
    }
  )
</script>
<style lang="scss" scoped>
  .config-edit-layout {
    box-sizing: border-box;
    height: 100%;
    min-height: 0;
    overflow: hidden;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 10px;
  }

  .config-edit-aside {
    display: flex;
    align-self: stretch;
    height: 100%;
    min-height: 0;
    overflow: hidden;
    background: var(--el-fill-color-extra-light);
    border-right: 1px solid var(--el-border-color);
    box-shadow: 1px 0 0 var(--el-border-color-lighter);
  }

  .config-edit-main {
    display: flex;
    min-width: 0;
    height: 100%;
    min-height: 0;
    padding: 0;
    overflow: hidden;
  }

  .config-panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    min-height: 0;
    overflow: hidden;
    background: var(--el-bg-color);
  }

  .config-category-panel {
    background: var(--el-fill-color-extra-light);
  }

  .config-category-header,
  .config-main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 56px;
    padding: 0 16px;
    background: linear-gradient(180deg, var(--el-bg-color), var(--el-fill-color-extra-light));
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .config-category-title,
  .config-main-title {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;

    b {
      font-size: 15px;
      line-height: 1.2;
      color: var(--art-text-gray-900);
    }

    span {
      max-width: 320px;
      overflow: hidden;
      font-size: 12px;
      color: var(--el-text-color-secondary);
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .config-category-panel :deep(.el-table__row) {
    cursor: pointer;
  }

  .config-form-body {
    flex: 1;
    min-height: 0;
    padding: 28px 32px;
    overflow: auto;
  }

  .config-form-body :deep(.el-form) {
    max-width: 860px;
  }

  .config-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 320px;
  }

  @media (max-width: 768px) {
    .config-edit-layout {
      flex-direction: column;
      height: auto;
    }

    .config-edit-aside {
      width: 100% !important;
      height: auto;
      border-right: 0;
    }

    .config-edit-main {
      height: auto;
      min-height: 420px;
    }
  }
</style>
<style>
  .form-builder-drawer {
    padding: 0 !important;
  }
</style>
