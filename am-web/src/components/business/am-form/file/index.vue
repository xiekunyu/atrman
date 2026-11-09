<template>
    <div>
            <div class="flex flex-wrap items-center gap-2" v-if="type==1">
                <div class="image-item" v-for="item in fileList">
                    <el-image :key="item.id" :src="getFileUrl(item, true)" fit="contain" :preview-src-list="[getFileUrl(item, false)]" />
                    <div v-if="!disabled" class="remove-icon" @click.stop="removeFile(item)">
                        <ArtSvgIcon icon="ri:close-circle-fill" class="text-lg" />
                    </div>
                </div>
               <div class="add-image" @click="openFileDialog" text type="primary" :icon="Plus" v-if="type==1 && !disabled">
                    <Plus />
                </div>
            </div>
            <div v-if="type==2" class="flex items-center gap-2">
                <b v-if="fileList.length > 0">  已选择 {{ fileList.length }} 个文件</b>
                <ElButton @click="openFileDialog" text type="primary" :icon="Plus" v-if="type==2 && !disabled">
                    选择文件
                </ElButton>
            </div>
        
       
        <AmFileSelection 
            ref="fileSelectionRef"
            v-model="fileDialogVisible"
            :title="placeholder"
            :multiple="multiple"
            :key="compKey"
            :accept="accept"
            :type="type"
            :default-value="selectValue"
            :display-data="fileList"
            @confirm="handleConfirm"
            @cancel="fileDialogVisible = false"
        />
    </div>
</template>
<script setup lang="ts">
import { Plus } from '@element-plus/icons-vue'
import AmFileSelection from '@/components/business/am-file-selection/index.vue'
import { getFileUrl } from '@/utils/file'

interface Props {
    modelValue: any
    displayData?: any
    placeholder?: string
    multiple?: boolean
    accept?: string
    type?: number
    disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: [],
    displayData: [],
    placeholder: '选择文件',
    multiple: false,
    accept: '',
    type: 1,
    disabled: false
})

const fileDialogVisible = ref(false)
const compKey = ref(0)
// 将 structureList 改为响应式 ref
const fileList = ref<any[]>([...props.displayData])

// 监听父组件传入的 displayData 变化
watch(() => props.displayData, (newVal) => {
  if (newVal && Array.isArray(newVal)) {
    fileList.value = [...newVal]
  }
}, { deep: true })
const emit = defineEmits<{
    (e: 'update:modelValue', value: any): void
    (e: 'confirm',value:any | number): void
    (e: 'cancel'): void
}>()

const selectValue = computed({
    get() {
        return props.modelValue
    },
    set(val) {
        emit('update:modelValue', val)
    }
})

onMounted(() => {
    selectValue.value = props.modelValue
})

const openFileDialog = () => {
    if (props.disabled) return
    fileDialogVisible.value = true
    compKey.value++
}

const handleConfirm = (data: any) => {
    const selectedValue = data.map((item: any) => item.id)
    fileList.value =[...data]
    emit('update:modelValue', selectedValue)
}

const removeFile = (item: any) => {
    if (props.disabled) return
    fileList.value = fileList.value.filter((i: any) => i.id !== item.id)
    selectValue.value = selectValue.value.filter((i: any) => i !== item.id)
}
</script>
<style lang="scss" scoped>
.image-item {
    width: 100px;
    height: 100px;
    border: 1px solid var(--art-gray-400);
    border-radius: 5px;
    position: relative;
    overflow: hidden;
    :deep(.el-image__inner) {
        width: 98px;
        height: 98px;
    }
    .remove-icon {
        display: none;
        position: absolute;
        top: 0;
        right: 5px;
        cursor: pointer;
    }
    &:hover {
        .remove-icon {
            display: block;
            &:hover {
                color: var(--el-color-danger);
            }
        }
    }
}
.add-image {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100px;
    height: 100px;
    padding: 30px;
    border: 1px dashed var(--art-gray-500);
    border-radius: 5px;
    cursor: pointer;
}
</style>