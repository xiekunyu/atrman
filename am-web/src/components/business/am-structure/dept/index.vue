<template>
  <div>
  <el-input
      v-model="filterText"
      class="w-60 mb-2"
      placeholder="请输入关键词搜索"
    />
  <template v-if="isLazyTree">
    <ElTree
        v-if="!filterText"
        ref="treeRef"
        node-key="key"
        show-checkbox
        :load="loadNode"
        lazy
        :props="props.treeProps"
        @check="handleTreeCheck"
        :check-strictly="props.checkStrictly"
        :default-checked-keys="props.checkedKeys"
        :default-expanded="isExpandAll"
      >
        <template #default="{ data }">
          <div v-if="data.stype=='dept'"><ArtSvgIcon icon="ri:folder-2-fill"  class="text-blue-500" /> {{ data.name }}</div>
          <div v-if="data.stype=='user'"><ArtSvgIcon icon="ri:user-fill"  class="text-orange-500" /> {{ data.name }}</div>
        </template>
      </ElTree>
      <ElTree
        v-else
        ref="treeRef"
        node-key="key"
        show-checkbox
        :data="treeData"
        :props="props.treeProps"
        @check="handleTreeCheck"
        :check-strictly="props.checkStrictly"
        :default-checked-keys="props.checkedKeys"
        :default-expanded="isExpandAll"
      >
        <template #default="{ data }">
          <div v-if="data.stype=='dept'"><ArtSvgIcon icon="ri:folder-2-fill"  class="text-blue-500" /> {{ data.name }}</div>
          <div v-if="data.stype=='user'"><ArtSvgIcon icon="ri:user-fill"  class="text-orange-500" /> {{ data.name }}</div>
        </template>
      </ElTree>
  </template>
  <template v-else>
    <ElTree
        ref="treeRef"
        node-key="key"
        show-checkbox
        :data="treeData"
        :props="props.treeProps"
        @check="handleTreeCheck"
        :check-strictly="props.checkStrictly"
        :default-checked-keys="props.checkedKeys"
        :default-expanded="isExpandAll"
        :filter-node-method="filterNode"
      >
        <template #default="{ data }">
          <ArtSvgIcon icon="ri:folder-2-fill"  class="text-blue-500" /> {{ data.name }}
        </template>
      </ElTree>
  </template>
  </div>
</template>

<script setup lang="ts">
  import deptApi from '@/api/system/dept'
  import {fetchGetDepartSubList} from '@/api/system-manage'
  import type { LoadFunction } from 'element-plus'
  import type { FilterNodeMethodFunction, TreeInstance } from 'element-plus'

  interface Tree {
    [key: string]: any
  }

  interface Props {
    type:number
    isExpandAll: boolean
    defaultData?: any,
    checkedKeys?: any,
    treeProps?: {
      children: string
      label: string
      isLeaf?: string
    },
    checkStrictly?: boolean
  }

const props = withDefaults(defineProps<Props>(), {
    type:1,
    isExpandAll: true,
    treeProps: () => ({ children: 'children', label: 'name', isLeaf: 'isLeaf' }),
    checkStrictly: true
  }
)

const isLazyTree = computed(() => {
  return [3,4,6,7].includes(props.type)
})

  interface Emits {
    (e: 'change', value: any[]): void
    (e: 'success'): void
  }

  const emit = defineEmits<Emits>()

  const isExpandAll = ref(true)
  const treeData = ref<any>([])
  const treeRef = ref<TreeInstance>()
  const allTreeNodes = ref<any[]>([])
  const filterText = ref('')
  const selectedData = ref<any[]>([])

  watch(filterText, (val) => {
    if(isLazyTree.value===false){
        treeRef.value!.filter(val)
    }else{
      searchLazyTree(val)
    }
  })


  const searchLazyTree = async (val:string) => {
    treeData.value = await fetchGetDepartSubList({keywords: val});
  }

  const filterNode: FilterNodeMethodFunction = (value: string, data: Tree) => {
    if (!value) return true
    return data.name.includes(value)
  }

  onMounted(async () => {
    if(props.type==1 || props.type==5){
      const res=await deptApi.list({})
      treeData.value=res.data.map((item: any) => {
        return item
      })
    }
    if(props.defaultData){
      selectedData.value=props.defaultData
    }
  })

  // 加载子节点
  const loadNode: LoadFunction = async (node, resolve) => {
    const id = node.data?.id
    if(node.data?.stype!='dept'){
      resolve([])
    }
    const data:any = await fetchGetDepartSubList({pid: id});
    if([2,3,6].includes(props.type)){
      data.forEach((item: any) => {
        if(item.stype=='dept'){
          item.disabled = true
        }
      })
    }
    allTreeNodes.value.push(...data)
    resolve(data as any[])
  }

  const handleTreeCheck = () => {
    const tree = treeRef.value
    if (!tree) return
    const checkedNodes = tree.getCheckedNodes()
    // 在selectedData.value和allTreeNodes中取差集，再合并checkNodes
    // 返回allTreeNodes中所有key
    if(isLazyTree.value && selectedData.value){
      const allNodes = filterText.value  ? treeData.value : allTreeNodes.value
      const allKeys = allNodes.map((item: any) => {
        return item.key
      })
      const diffNodes = selectedData.value.filter((item: any) => {
        return !allKeys.includes(item.key)
      })
      checkedNodes.push(...diffNodes)
      selectedData.value=checkedNodes
    }
    emit('change', checkedNodes)
  }

  const setCheckedKeys = (keys: any[]) => {
    const tree = treeRef.value
    if (!tree) return
    tree.setCheckedKeys(keys)
  }
  defineExpose({
        setCheckedKeys
    })
  </script>