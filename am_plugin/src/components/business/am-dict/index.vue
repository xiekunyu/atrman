<!-- 字典组件 -->
<template>
  <div class="sa-dict-wrapper">
    <template v-if="render === 'tag'">
      <ElTag
        v-for="(item, index) in dictList"
        :key="index"
        :size="size"
        :style="{
          backgroundColor: getColor(getData(item)?.color, 'bg'),
          borderColor: getColor(getData(item)?.color, 'border'),
          color: getColor(getData(item)?.color, 'text')
        }"
        :round="round"
        class="mr-1 last:mr-0"
      >
        {{ getData(item)?.name || item }}
      </ElTag>
    </template>
    <template v-else>
      <span v-for="(item, index) in dictList" :key="index">
        {{ getData(item)?.name || item }}{{ index < dictList.length - 1 ? '、' : '' }}
      </span>
    </template>
  </div>
</template>

<script setup lang="ts">
  import { useDictStore } from '@/store/modules/dict'

  defineOptions({ name: 'AmDict' })

  interface Props {
    /** 字典类型 */
    dict: string
    /** 字典值（支持字符串或数组） */
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

  const dictStore = useDictStore()

  // 统一处理 value，转换为数组格式
  const dictList = computed(() => {
    if (Array.isArray(props.value)) {
      return props.value.map((v) => String(v))
    }
    return props.value !== undefined && props.value !== null && props.value !== ''
      ? [String(props.value)]
      : []
  })

  // 根据值获取字典数据
  const getData = (value: string) => dictStore.getDataByValue(props.dict, value)

  const getColor = (color: string | undefined, type: 'bg' | 'border' | 'text') => {
    // 如果没有指定颜色，使用默认主色调
    if (!color) {
      const colors = {
        bg: 'var(--el-color-primary-light-9)',
        border: 'var(--el-color-primary-light-8)',
        text: 'var(--el-color-primary)'
      }
      return colors[type]
    }

    // 根据类型返回不同的颜色变体
    switch (type) {
      case 'bg':
        // 背景色 - 更浅的版本
        return `${color}33`
      case 'border':
        // 边框色 - 中等亮度
        return `${color}66`
      case 'text':
        // 文字色 - 原始颜色
        return color
      default:
        return color
    }
  }
</script>
