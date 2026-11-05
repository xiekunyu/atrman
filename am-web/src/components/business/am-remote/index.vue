<!-- 远程数据组件 -->
<template>
  <div class="sa-dict-wrapper">
    <template v-if="render === 'tag'">
      <ElTag
        v-for="(item, index) in remoteList"
        :key="index"
        :size="size"
        :round="round"
        class="mr-1 last:mr-0"
      >
        {{item?.label}}
      </ElTag>
    </template>
    <template v-else>
      <span v-for="(item, index) in remoteList" :key="index">
        {{item?.label}} {{ index < remoteList.length - 1 ? '、' : '' }}
      </span>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { useRemoteDataStore } from '@/store/modules/remoteData'

  defineOptions({ name: 'AmRemote' })

  interface Props {
    /** 远程数据类型 */
    remote: string
    /** 远程数据值（支持字符串或数组） */
    value: string | string[] | number | number[]
    /** 渲染方式 */
    render?: string
    /** 标签大小 */
    size?: 'large' | 'default' | 'small'
    /** 是否圆角 */
    round?: boolean
  }

  const props = withDefaults(defineProps<Props>(), {
    render: 'tag',
    size: 'default',
    round: false
  })

  const remoteStore = useRemoteDataStore()

  // 统一处理 value，转换为数组格式
  const remoteList = computed<any[]>(() => {
    const result = remoteStore.findOption(props.remote, props.value)
    // 如果返回的是 Promise，需要等待解析
    if (result instanceof Promise) {
      return []
    }
    return result || []
  })
</script>
