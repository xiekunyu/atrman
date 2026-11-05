<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="flex justify-between items-center">
          <b>通知模板管理</b>
          <div class="flex flex-wrap am-header-actions">
            <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshData" />
            <ArtButtonTable v-auth="'notify:template:save'" type="add" @click="showDialog('add')" />
          </div>
        </div>
      </template>

      <ArtTable
        :data="data"
        :columns="columns"
        :loading="loading"
        @selection-change="handleSelectionChange"
      >
        <template #channels="{ row }">
          <div class="flex gap-1">
            <ElTag size="small" type="primary">邮件</ElTag>
            <ElTag size="small" type="success">站内信</ElTag>
            <ElTag v-if="row.sms_template_id || row.sms_content" size="small" type="warning"
              >短信</ElTag
            >
          </div>
        </template>
        <template #sms_template_id="{ row }">
          <span>{{ row.sms_template_id || '-' }}</span>
        </template>
        <template #status="{ row }">
          <ElSwitch
            v-model="row.status"
            :active-value="1"
            :inactive-value="0"
            v-auth="'notify:template:changeStatus'"
            @change="handleStatusChange(row)"
          />
        </template>
        <template #operation="{ row }">
          <div class="flex">
            <ArtButtonTable
              type="view"
              icon="ri:flask-line"
              v-auth="'notify:template:test'"
              @click="handleTest(row)"
              title="测试"
            />
            <ArtButtonTable
              v-auth="'notify:template:update'"
              type="edit"
              @click="showDialog('edit', row)"
            />
            <ArtButtonTable
              v-auth="'notify:template:delete'"
              type="delete"
              @click="deleteRow(row, deleteTemplate, refreshData)"
            />
          </div>
        </template>
      </ArtTable>
    </ElCard>

    <TemplateDialog
      v-model:visible="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      @submit="handleDialogSubmit"
    />

    <TestDialog v-model:visible="testDialogVisible" :template-data="testTemplateData" />
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import notifyApi from '@/api/notify'
  import TemplateDialog from './modules/template-dialog.vue'
  import TestDialog from './modules/test-dialog.vue'

  defineOptions({ name: 'NotifyTemplate' })

  const { dialogType, dialogVisible, dialogData, showDialog, handleSelectionChange, deleteRow } =
    tableOperation()

  const testDialogVisible = ref(false)
  const testTemplateData = ref<any>({})

  const handleTest = (row: any) => {
    testTemplateData.value = row
    testDialogVisible.value = true
  }

  const deleteTemplate = (params: any) => notifyApi.template.delete(params)

  const { columns, data, loading, refreshData } = useTable({
    core: {
      apiFn: notifyApi.template.list,
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'name', label: '模板名称', minWidth: 140 },
        { prop: 'code', label: '模板编码', width: 140 },
        { prop: 'source', label: '来源', width: 100 },
        { prop: 'channels', label: '支持通道', width: 200, useSlot: true },
        { prop: 'sms_template_id', label: '短信模板ID', width: 140, useSlot: true },
        { prop: 'status', label: '状态', width: 80, useSlot: true },
        { prop: 'operation', label: '操作', width: 170, useSlot: true }
      ]
    }
  })

  const handleStatusChange = async (row: any) => {
    await notifyApi.template.changeStatus({ id: row.id, status: row.status })
  }

  const handleDialogSubmit = () => {
    dialogVisible.value = false
    dialogData.value = {}
    setTimeout(() => refreshData(), 300)
  }
</script>
