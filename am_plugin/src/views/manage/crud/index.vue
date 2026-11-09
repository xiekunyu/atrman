<template>
  <div class="crud-page">
    <ElCard shadow="never">
      <template #header>
        <div class="card-header">
          <span>CRUD 示例（请求插件自己的后端接口）</span>
        </div>
      </template>

      <!-- 搜索栏 -->
      <ElForm inline class="search-form">
        <ElFormItem label="关键词">
          <ElInput v-model="search.keyword" placeholder="请输入搜索关键词" clearable />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" @click="loadData">搜索</ElButton>
          <ElButton type="success" @click="handleAdd">新增</ElButton>
        </ElFormItem>
      </ElForm>

      <!-- 数据表格 -->
      <ElTable :data="tableData" border stripe v-loading="loading">
        <ElTableColumn prop="id" label="ID" width="80" />
        <ElTableColumn prop="name" label="名称" />
        <ElTableColumn prop="status" label="状态" width="100">
          <template #default="{ row }">
            <ElTag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '启用' : '禁用' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="created_at" label="创建时间" width="180" />
        <ElTableColumn label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" @click="handleEdit(row)">编辑</ElButton>
            <ElButton text type="danger" @click="handleDelete(row)">删除</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 分页 -->
      <ElPagination
        v-model:current-page="pagination.page"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        layout="total, sizes, prev, pager, next"
        class="pagination"
        @size-change="loadData"
        @current-change="loadData"
      />
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ref, reactive, onMounted } from 'vue'
  import { request, invoke, useUser } from '@/sdk'

  defineOptions({ name: 'CrudPage' })

  const { user } = useUser()

  const loading = ref(false)
  const tableData = ref<any[]>([])
  const search = reactive({ keyword: '' })
  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })

  /** 加载列表数据 */
  const loadData = async () => {
    loading.value = true
    try {
      const data = await request.get<{ list: any[]; total: number }>('/plugin/am_demo/list', {
        params: {
          page: pagination.page,
          pageSize: pagination.pageSize,
          keyword: search.keyword
        }
      })
      tableData.value = data?.list || []
      pagination.total = data?.total || 0
    } catch {
      tableData.value = [
        { id: 1, name: '示例数据 1', status: 1, created_at: '2025-01-01 00:00:00' },
        { id: 2, name: '示例数据 2', status: 0, created_at: '2025-01-02 00:00:00' }
      ]
      pagination.total = 2
    } finally {
      loading.value = false
    }
  }

  /** 新增 */
  const handleAdd = async () => {
    try {
      const result = await invoke('form.render', {
        title: '新增记录',
        items: [
          { key: 'name', label: '名称', type: 'input', placeholder: '请输入名称', required: true },
          { key: 'status', label: '状态', type: 'select', options: [
            { label: '启用', value: 1 },
            { label: '禁用', value: 0 }
          ]}
        ],
        model: {}
      })
      if (result?.cancelled) return
      await request.post('/plugin/am_demo/save', result)
      await invoke('ui.message', { type: 'success', message: '新增成功' })
      loadData()
    } catch (e: any) {
      await invoke('ui.message', { type: 'error', message: e.message || '操作失败' })
    }
  }

  /** 编辑 */
  const handleEdit = async (row: any) => {
    try {
      const result = await invoke('form.render', {
        title: '编辑记录',
        items: [
          { key: 'name', label: '名称', type: 'input', required: true },
          { key: 'status', label: '状态', type: 'select', options: [
            { label: '启用', value: 1 },
            { label: '禁用', value: 0 }
          ]}
        ],
        model: { name: row.name, status: row.status }
      })
      if (result?.cancelled) return
      await request.post('/plugin/am_demo/update', { id: row.id, ...result })
      await invoke('ui.message', { type: 'success', message: '更新成功' })
      loadData()
    } catch (e: any) {
      await invoke('ui.message', { type: 'error', message: e.message || '操作失败' })
    }
  }

  /** 删除 */
  const handleDelete = async (row: any) => {
    try {
      const confirmed = await invoke('ui.confirm', {
        title: '确认删除',
        message: `确定删除「${row.name}」吗？`
      })
      if (!confirmed) return
      await request.post('/plugin/am_demo/delete', { id: row.id })
      await invoke('ui.message', { type: 'success', message: '删除成功' })
      loadData()
    } catch (e: any) {
      await invoke('ui.message', { type: 'error', message: e.message || '操作失败' })
    }
  }

  onMounted(() => {
    loadData()
  })
</script>

<style scoped>
  .crud-page {
    padding: 0;
  }

  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .search-form {
    margin-bottom: 16px;
  }

  .pagination {
    margin-top: 16px;
    justify-content: flex-end;
  }
</style>
