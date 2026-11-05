<template>
  <ElDialog
    v-model="visible"
    title="菜单权限"
    width="520px"
    align-center
    class="el-dialog-border"
    @close="handleClose"
  >
    <ElScrollbar height="70vh">
      <ElTree
        ref="treeRef"
        :data="menuLists"
        show-checkbox
        node-key="id"
        :default-expand-all="isExpandAll"
        :props="defaultProps"
        :check-strictly="checkStrictly"
        @check="handleTreeCheck"
      >
        <template #default="{ data }">
          <div class="flex-c gap-2">
            <span>{{ data.label }}</span>
            <ElTag :type="MENU_TYPES[data.type as keyof typeof MENU_TYPES].type" size="small">{{
              MENU_TYPES[data.type as keyof typeof MENU_TYPES].text
            }}</ElTag>
          </div>
        </template>
      </ElTree>
    </ElScrollbar>
    <template #footer>
      <ElButton @click="toggleCheck" style="margin-left: 8px">{{
        checkStrictly ? '非关联选择模式' : '关联选择模式'
      }}</ElButton>
      <ElButton @click="toggleExpandAll">{{ isExpandAll ? '全部收起' : '全部展开' }}</ElButton>
      <ElButton @click="toggleSelectAll" style="margin-left: 8px">{{
        isSelectAll ? '取消全选' : '全部选择'
      }}</ElButton>
      <ElButton type="primary" v-auth="'system:role:setPermissions'" @click="savePermission"
        >保存</ElButton
      >
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { formatMenuTitle } from '@/utils/router'
  import menuApi from '@/api/system/menu'
  import roleApi from '@/api/system/role'

  type RoleListItem = Partial<Api.SystemManage.RoleListItem>

  interface Props {
    modelValue: boolean
    editData?: RoleListItem
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'success'): void
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    editData: undefined
  })

  const emit = defineEmits<Emits>()

  const treeRef = ref()
  const isExpandAll = ref(true)
  const isSelectAll = ref(false)
  const menuLists = ref<any[]>([])
  const checkStrictly = ref(true)

  const MENU_TYPES = {
    '1': { type: 'info' as const, text: '目录' },
    '2': { type: 'success' as const, text: '菜单' },
    '3': { type: 'warning' as const, text: '按钮' },
    '4': { type: 'danger' as const, text: '外链' }
  } as const

  /**
   * 弹窗显示状态双向绑定
   */
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })

  /**
   * 菜单节点类型
   */
  interface MenuNode {
    id?: string | number
    name?: string
    label?: string
    meta?: {
      title?: string
      authList?: Array<{
        authMark: string
        title: string
        checked?: boolean
      }>
    }
    children?: MenuNode[]
    [key: string]: any
  }

  /**
   * 初始化页面数据
   */
  const initPage = async () => {
    // 菜单列表
    const menuTree = await menuApi.getMenuTree()
    menuLists.value = menuTree as any[]
    // 角色数据
    const data = await roleApi.getPermissions(props.editData?.id || 0)
    // 菜单异步渲染完成后再回填勾选，否则 treeRef 尚未挂载时静默丢失已授权数据
    await nextTick()
    treeRef.value?.setCheckedKeys(data.menu_ids ?? [])
  }

  /**
   * 树形组件配置
   */
  const defaultProps = {
    children: 'children',
    label: (data: any) => formatMenuTitle(data.meta?.title) || data.label || ''
  }

  /**
   * 监听弹窗打开，初始化权限数据
   */
  watch(
    () => props.modelValue,
    (newVal) => {
      if (newVal && props.editData) {
        initPage()
      }
    }
  )

  /**
   * 关闭弹窗并清空选中状态
   */
  const handleClose = () => {
    visible.value = false
    treeRef.value?.setCheckedKeys([])
  }

  /**
   * 保存权限配置
   */
  const savePermission = async () => {
    const tree = treeRef.value
    if (!tree) return
    const checkedKeys = tree.getCheckedKeys()
    // 全不勾选是合法操作（回收该角色全部菜单权限），必须确认后提交，
    // 不能静默 return——那会让「保存」按钮看起来失灵，旧授权也清不掉
    if (!checkedKeys.length) {
      try {
        await ElMessageBox.confirm(
          '未勾选任何菜单，保存将清空该角色的全部菜单权限，确认继续？',
          '提示',
          {
            type: 'warning'
          }
        )
      } catch {
        return
      }
    }
    await roleApi.setPermissions({
      id: props.editData?.id || 0,
      menu_ids: checkedKeys
    })
    emit('success')
    handleClose()
  }

  /**
   * 切换全部展开/收起状态
   */
  const toggleExpandAll = () => {
    const tree = treeRef.value
    if (!tree) return

    const nodes = tree.store.nodesMap
    // 这里保留 any，因为 Element Plus 的内部节点类型较复杂
    Object.values(nodes).forEach((node: any) => {
      node.expanded = !isExpandAll.value
    })

    isExpandAll.value = !isExpandAll.value
  }

  /**
   * 切换全选/取消全选状态
   */
  const toggleSelectAll = () => {
    const tree = treeRef.value
    if (!tree) return

    if (!isSelectAll.value) {
      const allKeys = getAllNodeKeys(menuLists.value)
      tree.setCheckedKeys(allKeys)
    } else {
      tree.setCheckedKeys([])
    }

    isSelectAll.value = !isSelectAll.value
  }

  /** 递归获取所有节点的 key */
  const getAllNodeKeys = (nodes: MenuNode[]): string[] => {
    const keys: string[] = []
    const traverse = (nodeList: MenuNode[]): void => {
      nodeList.forEach((node) => {
        if (node.id) keys.push(node.id as string)
        if (node.children?.length) traverse(node.children)
      })
    }
    traverse(nodes)
    return keys
  }

  /**
   * 处理树节点选中状态变化
   * 同步更新全选按钮状态
   */
  const handleTreeCheck = () => {
    const tree = treeRef.value
    if (!tree) return

    const checkedKeys = tree.getCheckedKeys()
    const allKeys = getAllNodeKeys(menuLists.value)

    isSelectAll.value = checkedKeys.length === allKeys.length && allKeys.length > 0
  }

  /**
   * 切换关联选择/非关联选择状态
   */
  const toggleCheck = () => {
    const tree = treeRef.value
    if (!tree) return

    checkStrictly.value = !checkStrictly.value
  }
</script>
