
<template>
  <ElDialog
    v-model="visible"
    :title="props.title || title"
    width="640px"
    align-center
    class="el-dialog-border"
    body-class="dialog-body-pd"
    @close="handleClose"
  >
  <ElContainer>
      <ElAside width="320px">
        <ElScrollbar height="50vh">
          <AmDictForm v-if="visibleStructure"  dict="structure_type" v-model="structureType" mode="button" style="margin-bottom: 15px;"/>
          <DeptTree v-if="structureType==1" ref="treeRef" :type="props.type" :isExpandAll="isExpandAll" :treeProps="{children: 'children', label: 'name', isLeaf: 'isLeaf'}" :checkedKeys="checkedKeys" :displayData="deptData"  @change="handleChangeTree" />
          <Tags v-if="structureType==2" :checkedKeys="pendingKeys" ref="tagsRef" @change="handleChangeTags" />
        
        </ElScrollbar>
      </ElAside>
      <ElMain>
        <ElScrollbar height="50vh">
          <slot name="structure-check-list" :selectedData="checkData">
            <div class="check-list">
              <div class="mb-2">已选择 <span class="font-bold">{{ checkData.length }}</span> 条数据</div>
              <div v-for="(item,index)  in checkData" :key="index"  class="flex justify-between items-center check-list-item">
                  <div class="flex items-center justify-between ">
                    <ArtSvgIcon v-if="item.stype=='dept'" icon="ri:folder-2-fill"  class="text-blue-500" /> 
                    <ArtSvgIcon v-else-if="item.stype=='user'" icon="ri:user-fill"  class="text-orange-500" /> 
                    <ArtSvgIcon v-else-if="item.stype=='tags'" icon="ri:price-tag-3-fill" class="text-green-500"/>
                    {{ item.name }}
                    <span class="ml-2">
                      <ElTag v-if="showMainDept && item.stype=='dept' && item.is_main" type="warning" size="small">主部门</ElTag>
                      <ElButton v-else-if="showMainDept && item.stype=='dept'" class="set-main-dept" type="primary" link size="small" @click.stop="setMainDept(item)">设为主部门</ElButton>
                      <slot name="item-name-after" :item="item" />
                    </span>
                  </div>
                  <div @click="removeItem(item)">
                    <ArtSvgIcon icon="ri:close-line"  class="text-red-500" />
                  </div>
              </div>
            </div>
          </slot>
        </ElScrollbar>
      </ElMain>
    </ElContainer>
    
    <template #footer>
      <slot name="footer">
        <ElButton @click="handleClose">取消</ElButton>
        <ElButton type="primary" @click="saveStructure">保存</ElButton>
      </slot>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import DeptTree from './dept/index.vue'
  import AmDictForm from '@/components/business/am-dict/form/index.vue'
  import Tags from './tags/index.vue'


  // type是组织架构选择的类型
  // 1：仅部门，2仅标签，3仅成员，4部门+成员，5部门+标签，6标签+成员，7全部
  interface Props {
    modelValue: boolean
    type:number,
    title:string,
    displayData?:any,
    defaultValue?:any,
    showMainDept?: boolean
  }

  interface Emits {
    (e: 'update:modelValue', value: boolean): void
    (e: 'update:displayData', value: any): void
    (e: 'success', data:any): void
  }

  const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    title:'部门选择',
    displayData:[],
    defaultValue:[],
    showMainDept: false
  })

  const emit = defineEmits<Emits>()

  const treeRef = ref()
  const isExpandAll = ref(true)
  const structureType = ref(1)
  const checkData = ref<any[]>([])
  const tagsData = ref<any[]>([])
  const deptData = ref<any[]>([])
  const checkedKeys = ref<any[]>([])
  const pendingKeys = ref<any[]>([])
  const title = ref('')

  /**
   * 弹窗显示状态双向绑定
   */
  const visible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })

  const visibleStructure = computed(() => [5,6,7].includes(props.type))
  const showMainDept = computed(() => props.showMainDept && [1,4,5,7].includes(props.type))

  const normalizeMainDept = () => {
    if (!showMainDept.value) return
    const deptItems = checkData.value.filter((item: any) => item.stype == 'dept')
    if (!deptItems.length) return
    const mainDept = deptItems.find((item: any) => item.is_main)
    if (!mainDept) {
      deptItems[0].is_main = 1
    }
    const mainDeptId = (mainDept || deptItems[0]).id
    deptItems.forEach((item: any) => {
      item.is_main = item.id == mainDeptId ? 1 : 0
    })
  }

  const setMainDept = (item: any) => {
    checkData.value.forEach((node: any) => {
      if (node.stype == 'dept') {
        node.is_main = node.id == item.id ? 1 : 0
      }
    })
    deptData.value.forEach((node: any) => {
      if (node.stype == 'dept') {
        node.is_main = node.id == item.id ? 1 : 0
      }
    })
    emit('update:displayData', checkData.value)
  }

  onMounted(() => {
    if(props.type==1){
      structureType.value=1
      title.value="部门选择"
    }else if(props.type==2){
      structureType.value=2
      title.value="标签选择"
    }else if(props.type==3){
      title.value="成员选择"
    }else{
      title.value="对象选择"
    }
    init()
  })
  
  // 初始化数据
  const init = () => {
    if(props.defaultValue){
      const treeNodeKeys:any = []
      props.defaultValue?.user_ids?.forEach((item: any) => {
        treeNodeKeys.push('user_'+item)
      })
      props.defaultValue?.dept_ids?.forEach((item: any) => {
        treeNodeKeys.push('dept_'+item)
      })
      checkedKeys.value = treeNodeKeys
      pendingKeys.value = props.defaultValue?.tags_ids
    }
    if(props.displayData){
      checkData.value = props.displayData
      tagsData.value = props.displayData.filter((item: any) => item.stype=='tags')
      deptData.value = props.displayData.filter((item: any) => item.stype=='dept' || item.stype=='user')
      normalizeMainDept()
    }
  }
  /**
   * 监听弹窗打开，初始化权限数据
   */
  watch(
    () => props.defaultValue,
    (newVal) => {
      if(newVal){
        init()
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
  const saveStructure = async () => {
    normalizeMainDept()
    const data=getCheckedKeys();
    emit('success',data)
    handleClose()
  }

// 部门和和成员选择器的部门选中状态变化
  const handleChangeTree = (checkedNodes: any[]) => {
    const previousMainDept = checkData.value.find((item: any) => item.stype == 'dept' && item.is_main)
    deptData.value = checkedNodes.map((item: any) => ({
      ...item,
      is_main: item.stype == 'dept' && previousMainDept?.id == item.id ? 1 : item.is_main
    }))
    checkData.value = [...tagsData.value, ...deptData.value];
    normalizeMainDept()
    checkedKeys.value = deptData.value.map((item: any) => item.key)
    emit('update:displayData', checkData.value)
  }

  /**
   * 处理标签选中状态变化
   */
  const handleChangeTags = (tags: any) => {
    tagsData.value = tags
    checkData.value=[...deptData.value,...tags];
    pendingKeys.value = tags.map((item: any) => item.id)
    emit('update:displayData', checkData.value)
  }

  // 移除选中数据
  const removeItem = (item: any) => {
    checkData.value = checkData.value.filter((i: any) => i.key !== item.key)
    if(item.stype=='tags'){
      const tagsKeys = pendingKeys.value;
        pendingKeys.value = tagsKeys.filter((i: any) => i != item.id)
        tagsData.value = tagsData.value.filter((i: any) => i.id != item.id)
        
    }else {
       checkedKeys.value = checkedKeys.value.filter((i: any) => i !== item.key)
       deptData.value = deptData.value.filter((i: any) => i.key !== item.key)
       normalizeMainDept()
       treeRef.value?.setCheckedKeys(checkedKeys.value)
    }
  }

  // 获取所有选中的数据
  const getCheckedKeys = () => {
    normalizeMainDept()
    const data:any={
      dept_ids:[],
      user_ids:[],
      tags_ids:[],
      dept_items:[]
    };
    checkData.value.forEach((item: any) => {
      if(item.stype=='dept'){
        data.dept_ids.push(item.id)
        data.dept_items.push({ id: item.id, is_main: item.is_main ? 1 : 0 })
      }else if(item.stype=='user'){
        data.user_ids.push(item.id)
      }else if(item.stype=='tags'){
        data.tags_ids.push(item.id)
      }
    })
    return data
  }

  const getCheckedData = () => {
    return checkData.value
  }

  defineExpose({
    getCheckedKeys,
    getCheckedData
  })
</script>

<style lang="scss" scoped>
.check-list{
  .check-list-item{
    height: 32px;
    line-height: 32px;
    cursor: pointer;
    padding: 0 10px;
    border-radius: 4px;
    .set-main-dept{
      display: none;
    }
    &:hover {
      background-color: var(--art-hover-color) !important;
      .set-main-dept{
        display: block;
      }
    }
  }
}

.el-container :deep(.el-main){
  padding:var(--el-dialog-padding-primary) 0 0 var(--el-dialog-padding-primary) !important;
}
.el-container :deep(.el-aside){
  padding:var(--el-dialog-padding-primary) var(--el-dialog-padding-primary) 0 0 !important;
  border-right: 1px solid var(--art-gray-300);
}
</style>
<style>
.dialog-body-pd{
  padding: 0 !important;
}
</style>
