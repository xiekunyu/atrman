<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="flex justify-between items-center">
          <b>短信服务商管理</b>
          <div class="flex flex-wrap am-header-actions">
            <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshData" />
            <ArtButtonTable
              v-auth="'notify:sms-provider:save'"
              type="add"
              @click="showDialog('add')"
            />
          </div>
        </div>
      </template>

      <ArtTable
        :data="data"
        :columns="columns"
        :loading="loading"
        @selection-change="handleSelectionChange"
      >
        <template #configInfo="{ row }">
          <ElTag size="small">{{ row.configInfo?.name || '-' }}</ElTag>
        </template>
        <template #status="{ row }">
          <ElSwitch
            v-model="row.status"
            :active-value="1"
            :inactive-value="0"
            v-auth="'notify:sms-provider:changeStatus'"
            @change="handleStatusChange(row)"
          />
        </template>
        <template #is_default="{ row }">
          <ElTag v-if="row.is_default" type="success" size="small">默认</ElTag>
          <ElButton
            v-else
            size="small"
            text
            type="primary"
            v-auth="'notify:sms-provider:setDefault'"
            @click="handleSetDefault(row)"
            >设为默认</ElButton
          >
        </template>
        <template #operation="{ row }">
          <div class="flex">
            <ArtButtonTable
              v-auth="'notify:sms-provider:update'"
              type="edit"
              @click="showDialog('edit', row)"
            />
            <ArtButtonTable
              v-auth="'notify:sms-provider:delete'"
              type="delete"
              :disabled="row.is_sys == 1"
              @click="deleteRow(row, smsProviderApi.delete, refreshData)"
            />
          </div>
        </template>
      </ArtTable>
    </ElCard>

    <ProviderDialog
      v-model:visible="dialogVisible"
      :type="dialogType"
      :edit-data="dialogData"
      @submit="handleDialogSubmit"
    />
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import smsProviderApi from '@/api/notify/sms-provider'
  import ProviderDialog from './modules/provider-dialog.vue'

  defineOptions({ name: 'SmsProvider' })

  const { dialogType, dialogVisible, dialogData, showDialog, handleSelectionChange, deleteRow } =
    tableOperation()

  const { columns, data, loading, refreshData } = useTable({
    core: {
      apiFn: smsProviderApi.list,
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'name', label: '服务商名称', width: 160 },
        { prop: 'configInfo', label: '服务商类型', width: 140, useSlot: true },
        { prop: 'code', label: '网关标识', width: 100 },
        { prop: 'remark', label: '备注' },
        { prop: 'status', label: '状态', width: 80, useSlot: true },
        { prop: 'is_default', label: '默认', width: 100, useSlot: true },
        { prop: 'operation', label: '操作', width: 130, useSlot: true }
      ]
    }
  })

  const handleStatusChange = async (row: any) => {
    await smsProviderApi.changeStatus({ id: row.id, status: row.status })
  }

  const handleSetDefault = async (row: any) => {
    await smsProviderApi.setDefault({ id: row.id })
    refreshData()
  }

  const handleDialogSubmit = () => {
    dialogVisible.value = false
    dialogData.value = {}
    setTimeout(() => refreshData(), 300)
  }
</script>
