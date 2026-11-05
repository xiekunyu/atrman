<template>
  <div class="am-dept-select">
    <el-tree-select
      v-model="selectValue"
      :data="treeData"
      v-bind="{ ...$attrs }"
    >
    </el-tree-select>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { ElTreeSelect } from 'element-plus'
import deptApi from '@/api/system/dept'

interface TreeNode {
  id: number
  name: string
  pid: number
  children?: TreeNode[]
  [key: string]: any
}

interface Props {
  modelValue: number | any
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: 0
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number | any): void
  (e: 'change', value: number | any, data?: TreeNode): void
}>()

const selectValue = computed({
    get() {
      return props.modelValue
    },
    set(val) {
      emit('update:modelValue', val)
    }
})
const treeData = ref<any[]>([])


onMounted(async () => {
    const res = await deptApi.list({ tree: true })
    treeData.value = res.data
})

watch(
  () => props.modelValue,
  (newValue) => {
    selectValue.value = newValue
  }
)
</script>

<style scoped>
.am-dept-select {
  width: 100%;
}

.am-dept-select :deep(.el-tree-select) {
  width: 100%;
}
</style>