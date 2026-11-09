<template>
    <div class="tags-list">
        <div class="flex justify-between items-center tags-item" :class="{'active': item.active}"  v-for="item in tagsList" :key="item.id" @click="handleTagClick(item)">
            <div><ArtSvgIcon icon="ri:price-tag-3-fill" class="text-green-500"/> {{ item.name }}</div>
        </div>
    </div>
</template>
<script setup lang="ts">
import { ref } from 'vue'
import tagsApi from '@/api/system/tags'

  interface Emits {
    (e: 'change', value: any): void
    (e: 'success'): void
  }

  interface Props {
    checkedKeys?: any,
  }
  const props = withDefaults(defineProps<Props>(), {
    checkedKeys: []
  })

    const emit = defineEmits<Emits>()

    const tagsList = ref<any>([])

    onMounted(async () => {
        tagsList.value = await tagsApi.list({})
        init(props.checkedKeys);
    })

    // 初始化数据
    const init = (checkedKeys: any[]) => {
      tagsList.value.forEach((tag: any) => {
        tag.active = checkedKeys.includes(tag.id)
      })
    }

    const handleTagClick = (item: any) => {
        // 实现多选
        tagsList.value.forEach((tag: any) => {
            if(tag.id === item.id){
                tag.active = !tag.active
            }
        })
        emit('change', tagsList.value.filter((tag:any) => tag.active))
    }


   /**
   * 监听弹窗打开，初始化权限数据
   */
  watch(
    () => props.checkedKeys,
    (newVal) => {
      if(newVal){
        init(newVal)
      }
    }
  )

    
</script>
<style lang="scss" scoped>
.tags-list {
  width: 100%;
  .tags-item {
    height: 32px;
    line-height: 32px;
    padding-left: 10px;
    margin-bottom: 5px;
    cursor: pointer;
    border-radius: 4px;
     .tags-more{
      display: none;
      padding-right:10px;
    }
    &:hover {
      background-color: var(--art-hover-color) !important;
    }
  }
  .active{
    background-color:var(--el-color-primary-light-9);
  }
}
</style>
