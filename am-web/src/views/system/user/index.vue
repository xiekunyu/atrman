<!-- 用户管理页面 -->
<!-- art-full-height 自动计算出页面剩余高度 -->
<!-- art-table-card 一个符合系统样式的 class，同时自动撑满剩余高度 -->
<!-- 更多 useTable 使用示例请移步至 功能示例 下面的高级表格示例或者查看官方文档 -->
<!-- useTable 文档：https://www.artd.pro/docs/zh/guide/hooks/use-table.html -->
<template>
  <div class="art-full-height">
    <div class="box-border flex gap-4 h-full max-md:block max-md:gap-0 max-md:h-auto">
      <div class="flex-shrink-0 w-80 h-full max-md:w-full max-md:h-auto max-md:mb-5">
        <ElCard class="am-card-header art-card-xs flex flex-col h-full mt-0" shadow="never">
          <template #header>
            <div class="flex justify-between items-center">
              <div>
                <AmDictForm dict="structure_type" v-model="architecture" mode="button" />
              </div>
              <div class="am-header-actions">
                <ArtButtonTable type="view" icon="ri:refresh-line" @click="refreshTree" />
                <ArtButtonTable v-auth="'system:dept:save'" type="add" @click="addDept('add')" />
              </div>
            </div>
            <div v-if="architecture === 1">
              <div class="text-sm text-gray-500 mt-2"
                >说明：部门可以包含子部门，子部门包含用户，选择部门可以设置部门的负责人（上级）。</div
              >
              <div class="grid grid-cols-2 gap-3 mt-2">
                <AmStatCard :value="allDept" label="部门数" />
                <AmStatCard :value="currentDept?.label || '所有成员'" label="当前部门" />
              </div>
            </div>
            <div v-if="architecture === 2">
              <div class="text-sm text-gray-500 mt-2"
                >说明：标签内包含了部门或者用户，方便对用户进行分类。</div
              >
              <div class="grid grid-cols-2 gap-3 mt-2">
                <AmStatCard :value="tagsList.length" label="标签数" />
                <AmStatCard :value="selectedTag?.name || '所有成员'" label="当前标签" />
              </div>
            </div>
          </template>
          <ElScrollbar>
            <ElTree
              v-show="architecture === 1"
              ref="treeRef"
              :data="treeData"
              :default-expand-all="true"
              :highlight-current="true"
              class="dept-tree"
              @node-click="handleNodeClick"
            >
              <template #default="{ data }">
                <div class="flex justify-between items-center dept-tree-node">
                  <div
                    ><ArtSvgIcon icon="ri:folder-2-fill" class="text-blue-500" />
                    {{ data.label }}</div
                  >
                  <ElDropdown class="dept-node-more">
                    <ArtSvgIcon icon="ri:more-2-fill" />
                    <template #dropdown>
                      <ElDropdownMenu>
                        <ElDropdownItem v-if="data.id != 0">
                          <div class="flex-c gap-2" @click="editDept(data)">
                            <ArtSvgIcon icon="ri:edit-line" />
                            <span>编辑部门</span>
                          </div>
                        </ElDropdownItem>
                        <ElDropdownItem>
                          <div class="flex-c gap-2" @click="addSubDept(data)">
                            <ArtSvgIcon icon="ri:node-tree" />
                            <span>添加子部门</span>
                          </div>
                        </ElDropdownItem>
                        <ElDropdownItem>
                          <div class="flex-c gap-2" @click="deleteDept(data)">
                            <ArtSvgIcon icon="ri:delete-bin-5-line" class="text-red-500" />
                            <span class="text-red-500">删除部门</span>
                          </div>
                        </ElDropdownItem>
                      </ElDropdownMenu>
                    </template>
                  </ElDropdown>
                </div>
              </template>
            </ElTree>
            <div v-show="architecture === 2" class="tags-list">
              <div
                class="flex justify-between items-center tags-item"
                :class="{ active: item.id === selectedTag.id }"
                v-for="item in tagsList"
                :key="item.id"
                @click="handleTagClick(item)"
              >
                <div>
                  <ArtSvgIcon icon="ri:price-tag-3-fill" class="text-green-500" />
                  {{ item.name }}
                  <ElTag
                    type="warning"
                    size="small"
                    class="tag-count"
                    v-if="item.user_ids.length > 0"
                  >
                    <ArtSvgIcon icon="ri:user-fill" class="text-orange-500" />&nbsp;{{
                      item.user_ids.length
                    }}
                  </ElTag>
                  <ElTag
                    type="primary"
                    size="small"
                    class="tag-count"
                    v-if="item.dept_ids.length > 0"
                  >
                    <ArtSvgIcon icon="ri:folder-2-fill" class="text-blue-500" />&nbsp;{{
                      item.dept_ids.length
                    }}
                  </ElTag>
                </div>
                <ElDropdown class="tags-more">
                  <ArtSvgIcon icon="ri:more-2-fill" />
                  <template #dropdown>
                    <ElDropdownMenu>
                      <ElDropdownItem>
                        <div class="flex-c gap-2" @click="handleTagUpdate(item)">
                          <ArtSvgIcon icon="ri:edit-line" />
                          <span>编辑标签名称</span>
                        </div>
                      </ElDropdownItem>
                      <ElDropdownItem>
                        <div class="flex-c gap-2" @click="manageTags(item)">
                          <ArtSvgIcon icon="ri:node-tree" />
                          <span>管理成员/部门</span>
                        </div>
                      </ElDropdownItem>
                      <ElDropdownItem>
                        <div class="flex-c gap-2" @click="deleteTag(item)">
                          <ArtSvgIcon icon="ri:delete-bin-5-line" class="text-red-500" />
                          <span class="text-red-500">删除标签</span>
                        </div>
                      </ElDropdownItem>
                    </ElDropdownMenu>
                  </template>
                </ElDropdown>
              </div>
            </div>
          </ElScrollbar>
        </ElCard>
      </div>
      <div class="flex flex-col flex-grow min-w-0">
        <!-- 搜索栏 -->
        <UserSearch
          v-model="searchForm"
          @search="handleSearch"
          @reset="resetSearchParams"
        ></UserSearch>

        <ElCard class="flex flex-col flex-1 min-h-0 art-table-card" shadow="never">
          <!-- 表格头部 -->
          <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
            <template #left>
              <ElSpace wrap>
                <ElButton @click="showDialog('add')" v-ripple v-auth="'system:user:save'"
                  >新建</ElButton
                >
                <ElButton
                  @click="showBatchSet"
                  :disabled="selectedRows.length == 0"
                  v-ripple
                  v-auth="'system:user:batchSet'"
                  type="success"
                  plain
                  >批量设置</ElButton
                >
                <ElButton
                  @click="batchDelete(userApi.batchDelete, refreshData)"
                  type="danger"
                  plain
                  :disabled="selectedRows.length == 0"
                  v-ripple
                  v-auth="'system:user:batchDelete'"
                >
                  删除
                </ElButton>
                <span v-if="modeValue == 1 && architecture === 1 && currentDept?.id !== ''">
                  <ElButton
                    @click="setLeader"
                    :disabled="visibleLeaderBtn"
                    type="warning"
                    plain
                    v-ripple
                    v-auth="'system:dept:setLeader'"
                    >设置/取消上级</ElButton
                  >
                </span>
              </ElSpace>
            </template>
          </ArtTableHeader>

          <!-- 表格 -->
          <ArtTable
            :loading="loading"
            :data="data"
            :columns="columns"
            :pagination="pagination"
            @sort-change="handleSortChange"
            @selection-change="handleSelectionChange"
            @row-dblclick="handleViewProfile"
            @pagination:size-change="handleSizeChange"
            @pagination:current-change="handleCurrentChange"
          >
            <!-- 操作列 -->
            <template #operation="{ row }">
              <div class="flex">
                <ArtButtonTable
                  v-if="row.id !== 1"
                  v-auth="'system:user:update'"
                  type="edit"
                  @click="showDialog('edit', row)"
                />
                <ArtButtonTable
                  v-if="row.id !== 1"
                  type="delete"
                  v-auth="'system:user:delete'"
                  @click="deleteRow(row, userApi.delete, refreshData)"
                />
                <ElDropdown>
                  <ArtIconButton
                    icon="ri:more-2-fill"
                    class="!size-8 bg-g-200 dark:bg-g-300/45 text-sm"
                  />
                  <template #dropdown>
                    <ElDropdownMenu>
                      <ElDropdownItem>
                        <div class="flex-c gap-2" @click="handleViewProfile(row)">
                          <ArtSvgIcon icon="ri:user-search-line" />
                          <span>查看资料</span>
                        </div>
                      </ElDropdownItem>
                      <ElDropdownItem v-if="row.id !== 1">
                        <div
                          v-auth="'system:user:editPass'"
                          class="flex-c gap-2"
                          @click="handlePassword(row)"
                        >
                          <ArtSvgIcon icon="ri:key-line" />
                          <span>修改密码</span>
                        </div>
                      </ElDropdownItem>
                    </ElDropdownMenu>
                  </template>
                </ElDropdown>
              </div>
            </template>
          </ArtTable>

          <!-- 用户弹窗 -->
          <UserDialog
            v-model:visible="dialogVisible"
            :type="dialogType"
            :edit-data="dialogData"
            @submit="handleDialogSubmit"
          />
          <BatchSetDialog
            v-model:visible="batchSetVisible"
            :users="selectedRows"
            @submit="handleBatchSetSubmit"
          />
          <UserProfileDialog
            v-model:visible="profileVisible"
            :user-id="profileUserId"
            @updated="handleProfileUpdated"
          />
        </ElCard>
      </div>
    </div>
    <!-- 部门弹窗 -->
    <DeptDialog
      v-model:visible="deptVisible"
      :type="deptType"
      :edit-data="deptData"
      :pid="pid"
      :key="compKey"
      @submit="handleDeptSubmit"
    />
    <!-- 标签弹窗 -->
    <AmStructure
      v-model:modelValue="amStructureVisible"
      :type="4"
      :key="compKey"
      :title="'管理部门和成员'"
      :default-value="structure"
      :display-data="structureList"
      @success="saveTagsStructure"
    >
    </AmStructure>
  </div>
</template>

<script setup lang="ts">
  import ArtButtonTable from '@/components/core/forms/art-button-table/index.vue'
  import { tableOperation } from '@/mixins/tableOperation'
  import { useTable } from '@/hooks/core/useTable'
  import userApi from '@/api/system/user'
  import deptApi from '@/api/system/dept'
  import tagsApi from '@/api/system/tags'
  import UserSearch from './modules/user-search.vue'
  import UserDialog from './modules/user-dialog.vue'
  import BatchSetDialog from './modules/batch-set-dialog.vue'
  import UserProfileDialog from './modules/user-profile-dialog.vue'
  import DeptDialog from './modules/dept-dialog.vue'
  import AmStructure from '@/components/business/am-structure/index.vue'
  import AmDictForm from '@/components/business/am-dict/form/index.vue'
  import { ElMessageBox, ElImage, ElTag } from 'element-plus'
  import { getFileUrl } from '@/utils/file'
  import defaultAvatar from '@imgs/user/avatar.webp'

  defineOptions({ name: 'User' })

  const treeData = ref<any>([])
  const deptVisible = ref(false)
  const deptType = ref('add')
  const deptData = ref<any>({})
  const pid = ref(0)
  const modeValue = ref(2)
  const architecture = ref(1)
  const selectedTag = ref<any>({})
  const tagsList = ref<any>([])
  const amStructureVisible = ref(false)
  const compKey = ref(0)
  const structure = ref<any>({})
  const structureList = ref<any>([])
  const currentTags = ref<any>([])
  const currentDept = ref<any>([])
  const treeRef = ref<any>()
  const allDept = ref(0)
  const batchSetVisible = ref(false)
  const profileVisible = ref(false)
  const profileUserId = ref<number>()

  onMounted(() => {
    initDeptTree()
    initTagsList()
  })
  // 刷新部门树
  const refreshTree = async () => {
    if (architecture.value == 1) {
      initDeptTree()
    } else {
      initTagsList()
    }
    currentDept.value = []
    selectedTag.value = {}
    Object.assign(searchParams, { dept_id: '', tags_id: '' })
    getData()
  }

  const initDeptTree = async () => {
    const res = await deptApi.list({ tree: true })
    treeData.value = res.data
    allDept.value = res.total
  }

  const initTagsList = async () => {
    tagsList.value = await tagsApi.list({})
  }
  // 编辑框
  const {
    dialogType,
    dialogVisible,
    dialogData,
    showDialog,
    selectedRows,
    batchDelete,
    handleSelectionChange,
    deleteRow
  } = tableOperation()

  // 搜索表单
  const searchForm = ref({
    username: undefined,
    nickname: undefined,
    gender: undefined,
    mobile: undefined,
    email: undefined,
    keywords: undefined,
    status: '',
    dept_id: undefined
  })

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
    handleSortChange,
    handleCurrentChange,
    refreshCreate,
    refreshUpdate,
    refreshData
  } = useTable({
    performance: {
      debounceTime: 500 // 搜索防抖
    },
    // 核心配置
    core: {
      apiFn: userApi.list,
      apiParams: {
        current: 1,
        size: 20,
        ...searchForm.value
      },
      columnsFactory: () => [
        { type: 'selection' }, // 勾选列
        { prop: 'id', width: 80, label: 'ID', sortable: true }, // ID
        {
          prop: 'userinfo',
          label: '用户名',
          width: 280,
          // visible: false, // 默认是否显示列
          formatter: (row: any) => {
            return h('div', { class: 'user flex-c' }, [
              h(ElImage, {
                class: 'size-9.5 rounded-md',
                src: getFileUrl(row.avatar, true) || defaultAvatar,
                previewSrcList: getFileUrl(row.avatar, false)
                  ? [getFileUrl(row.avatar, false)]
                  : [defaultAvatar],
                fit: 'cover',
                // 图片预览是否插入至 body 元素上，用于解决表格内部图片预览样式异常
                previewTeleported: true
              }),
              h('div', { class: 'ml-2' }, [
                h('p', { class: 'user-name' }, row.username),
                h('p', { class: 'email' }, row.email)
              ])
            ])
          }
        },
        {
          prop: 'nickname',
          label: '姓名',
          width: 180,
          formatter: (row: any) => {
            return h('div', { class: 'user flex-c' }, [
              h('div', {}, row.nickname),
              row.is_leader
                ? h(ElTag, { class: 'ml-2', size: 'small', type: 'warning' }, () => '上级')
                : h('span', {}, ''),
              (row.is_cur_dept == 1 && row.is_leader == 0) ||
              (row.cur_dept_id == currentDept.value?.id && row.is_leader == 0)
                ? h(ElTag, { class: 'ml-2', size: 'small', type: 'primary' }, () => '成员')
                : h('span', {}, '')
            ])
          }
        },
        {
          prop: 'gender',
          label: '性别',
          width: 80,
          sortable: true,
          amType: 'dict',
          dict: 'gender'
        },
        {
          prop: 'depts',
          label: '主部门',
          formatter: (row: any) => {
            return h(
              ElTag,
              {
                type: row.cur_dept_id == currentDept.value?.id ? 'primary' : 'info'
              },
              () => row.dept?.name || '顶级部门'
            )
          }
        },
        { prop: 'mobile', label: '手机号' },
        { prop: 'create_time', label: '创建日期', width: 180, sortable: true },
        { prop: 'status', label: '状态', width: 70, amType: 'dict', dict: 'status' },
        { prop: 'operation', label: '操作', width: 150, fixed: 'right', useSlot: true }
      ]
    }
  })

  const handleSearch = (params: Record<string, any>) => {
    Object.assign(searchParams, params)
    getData()
  }

  const handlePassword = (row: any) => {
    ElMessageBox.prompt('请输入新密码', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      inputPattern: /^.{6,20}$/,
      inputErrorMessage: '密码长度在6到20之间',
      type: 'warning'
    }).then(({ value }) => {
      userApi.editPass({ id: row.id, password: value })
    })
  }

  const showBatchSet = () => {
    batchSetVisible.value = true
  }

  const handleViewProfile = (row: any) => {
    profileUserId.value = row.id
    profileVisible.value = true
  }

  const handleProfileUpdated = async () => {
    await initDeptTree()
    refreshData()
  }

  const handleBatchSetSubmit = async () => {
    batchSetVisible.value = false
    await initDeptTree()
    refreshData()
  }

  const handleDialogSubmit = async () => {
    try {
      if (dialogType.value === 'add') {
        refreshCreate() // 回到第一页
      } else {
        refreshUpdate() // 刷新数据
      }
      dialogVisible.value = false
      dialogData.value = {}
    } catch (error) {
      console.error('提交失败:', error)
    }
  }

  const handleDeptSubmit = async () => {
    try {
      await initDeptTree()
      dialogVisible.value = false
      dialogData.value = {}
      refreshData()
    } catch {
      // 表单校验未通过，无需额外处理
    }
  }

  const addDept = (type: string) => {
    if (architecture.value == 1) {
      pid.value = 0
      deptVisible.value = true
      deptType.value = type
      deptData.value = {}
      compKey.value++
    } else {
      ElMessageBox.prompt('请输入标签名称', '新增标签', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        inputPattern: /^.{2,20}$/,
        inputErrorMessage: '标签名称长度在2到20之间',
        type: 'info'
      }).then(({ value }) => {
        tagsApi.save({ name: value })
        setTimeout(() => {
          initTagsList()
        }, 500)
      })
    }
  }

  // 添加子部门
  const addSubDept = (raw: any) => {
    pid.value = raw.id
    deptVisible.value = true
    deptType.value = 'add'
    deptData.value = {}
  }

  // 编辑部门
  const editDept = (raw: any) => {
    deptVisible.value = true
    deptType.value = 'edit'
    deptData.value = raw
  }

  /**
   * 处理部门节点点击事件
   */
  const handleNodeClick = (data: any) => {
    modeValue.value = 1
    currentDept.value = data
    Object.assign(searchParams, { dept_id: data.id, tags_id: '' })
    getData()
  }

  // 点击标签处理
  const handleTagClick = (item: any) => {
    selectedTag.value = item
    Object.assign(searchParams, { tags_id: item.id, dept_id: '' })
    getData()
  }

  // 修改标签
  const handleTagUpdate = (item: any) => {
    ElMessageBox.prompt('请输入标签名称', '修改标签', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      inputPattern: /^.{2,20}$/,
      inputErrorMessage: '标签名称长度在2到20之间',
      type: 'info',
      inputValue: item.name
    }).then(({ value }) => {
      tagsApi.update({ id: item.id, name: value })
      setTimeout(() => {
        initTagsList()
      }, 500)
    })
  }

  // 删除标签
  const deleteTag = (item: any) => {
    ElMessageBox.confirm('确定删除标签吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(() => {
      tagsApi.delete({ id: item.id })
      setTimeout(() => {
        initTagsList()
      }, 500)
    })
  }

  // 删除部门
  const deleteDept = (item: any) => {
    ElMessageBox.confirm('确定删除部门吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(() => {
      deptApi.delete({ id: item.id })
      setTimeout(() => {
        initDeptTree()
      }, 500)
    })
  }

  // 管理标签
  const manageTags = async (item: any) => {
    currentTags.value = item
    const data = await tagsApi.read(item.id)
    structure.value = data.structure
    structureList.value = data.structureList
    amStructureVisible.value = true
    compKey.value++
  }

  // 保存标签结构
  const saveTagsStructure = async (data: any) => {
    tagsApi.update({
      id: currentTags.value.id,
      name: currentTags.value.name,
      user_ids: data.user_ids,
      dept_ids: data.dept_ids
    })
    setTimeout(() => {
      initTagsList()
    }, 500)
  }

  // 控制设置部门领导按钮是否可见
  const visibleLeaderBtn = computed(() => {
    const length = selectedRows.value.length
    if (length == 0 || currentDept.value?.id === '') {
      return true
    }
    if (length > 1) {
      return true
    }
    const item = selectedRows.value[0]
    if (item?.cur_dept_id == currentDept.value.id) {
      return false
    }
    return true
  })

  // 设置部门领导
  const setLeader = async () => {
    if (currentDept.value?.id === '') {
      ElMessage.error('请选择部门')
      return
    }
    if (selectedRows.value.length != 1) {
      ElMessage.error('只能选择一条数据')
      return
    }
    if (selectedRows.value[0].cur_dept_id != currentDept.value.id) {
      ElMessage.error('部门和人员不匹配')
      return
    }
    await deptApi.setLeader({ user_id: selectedRows.value[0].id, dept_id: currentDept.value.id })
    setTimeout(() => {
      getData()
    }, 500)
  }
</script>

<style lang="scss" scoped>
  .dept-tree-node {
    width: 100%;
    height: 40px;
    .dept-node-more {
      display: none;
      padding-right: 10px;
    }
    &:hover .dept-node-more {
      display: block;
      border: none;
    }
  }
  .dept-tree :deep(.el-tree-node__content) {
    height: 40px;
    line-height: 40px;
    border-radius: 0;
  }

  .tags-list {
    width: 100%;
    .tags-item {
      height: 40px;
      line-height: 40px;
      padding-left: 10px;
      margin-bottom: 5px;
      cursor: pointer;
      .tags-more {
        display: none;
        padding-right: 10px;
      }
      .tag-count {
        margin-left: 5px;
        padding: 0 3px !important;
        :deep(.el-tag__content) {
          align-items: center;
          align-content: center;
          display: flex;
          padding: 0 !important;
        }
      }
      &:hover .tags-more {
        display: block;
        border: none;
      }
      &:hover {
        background-color: var(--art-hover-color) !important;
      }
    }
    .active {
      background-color: var(--el-color-primary-light-9);
      .tags-more {
        display: block !important;
      }
    }
  }
</style>
