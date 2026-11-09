<template>
    <div class="flex align-center gap-2 flex-wrap items-center">
      <AmTags v-for="item in structureList" :key="item.id" v-if="structureList.length > 0" type="success" @close="handleClose(item)">
        <span class="flex items-center gap-1">
          <ArtSvgIcon v-if="item.stype=='dept'" icon="ri:folder-2-fill"  class="text-blue-500" /> 
          <ArtSvgIcon v-else-if="item.stype=='user'" icon="ri:user-fill"  class="text-orange-500" /> 
          <ArtSvgIcon v-else-if="item.stype=='tags'" icon="ri:price-tag-3-fill" class="text-green-500"/>
            {{ item.name }} 
            <ElTag v-if="item.stype=='dept' && item.is_main" class="main-dept-tag" type="info" size="small">主</ElTag>
        </span>
      </AmTags>
      <ElButton @click="amStructureVisible = true;compKey++" text type="primary" :icon="Plus">
            添加
      </ElButton>
        <AmStructure
            ref="structureRef"
            v-model="amStructureVisible"
            :type="type"
            :key="compKey"
            :title="placeholder"
            :default-value="structure"
            :display-data="structureList"
            :show-main-dept="props.showMainDept"
            @success="saveTagsStructure"
            @update:displayData="handleUpdatedisplayData"
            >
        </AmStructure>
    </div>
    
</template>
<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Plus } from '@element-plus/icons-vue'
import AmStructure from '../../am-structure/index.vue'

interface Props {
  modelValue: any,
  displayData: any,
  type: number,
  placeholder?: string,
  showMainDept?: boolean,
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => [],
  type: 7,
  displayData: () => [],
  placeholder: '管理部门和成员',
  showMainDept: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | any): void
  (e: 'update:displayData', value: any): void
}>()

const amStructureVisible = ref(false)
const compKey = ref(0)
const structureRef = ref<any>()

// 将 structureList 改为响应式 ref
const structureList = ref<any[]>([...props.displayData])

// 监听父组件传入的 displayData 变化
watch(() => props.displayData, (newVal) => {
  if (newVal && Array.isArray(newVal)) {
    structureList.value = [...newVal]
  }
}, { deep: true })

const structure = computed({
    get() {
        return props.modelValue
    },
    set(val) {
        emit('update:modelValue', val)
    }
})

const normalizeMainDept = () => {
  if (!props.showMainDept) return
  const deptList = structureList.value.filter((item: any) => item.stype == 'dept')
  if (!deptList.length) {
    structure.value.dept_items = []
    return
  }
  const mainDept = deptList.find((item: any) => item.is_main) || deptList[0]
  deptList.forEach((item: any) => {
    item.is_main = item.id == mainDept.id ? 1 : 0
  })
  structure.value.dept_items = deptList.map((item: any) => ({ id: item.id, is_main: item.is_main ? 1 : 0 }))
}

const handleClose = (item: any) => {
  structureList.value = structureList.value.filter((i: any) => i.key !== item.key )
  if(item.stype=='dept'){
    structure.value.dept_ids = structure.value?.dept_ids?.filter((i: any) => i !== item.id)
    structure.value.dept_items = structure.value?.dept_items?.filter((i: any) => i.id !== item.id)
    normalizeMainDept()
  }
  if(item.stype=='user'){
    structure.value.user_ids = structure.value?.user_ids?.filter((i: any) => i !== item.id)
  }
  if(item.stype=='tags'){
    structure.value.tags_ids = structure.value?.tags_ids?.filter((i: any) => i !== item.id)
  }
  updateStructure()
}

// 处理子组件更新 displayData
const handleUpdatedisplayData = (data: any) => {
  structureList.value = [...data]
  updateStructure()
}



const saveTagsStructure = (val: any) => {
  structure.value = val
  const checkedData = structureRef.value?.getCheckedData?.()
  console.log("🚀 ~ saveTagsStructure ~ checkedData:", checkedData)
  if (checkedData) {
    structureList.value = [...checkedData]
    updateStructure()
  }
}

const updateStructure = () => {
  emit('update:displayData', structureList.value)
}
</script>
<style scoped lang="scss">
.mr-2 {
  margin-right: 4px;
}
.main-dept-tag{
  padding: 1px 3px;
}
</style>
