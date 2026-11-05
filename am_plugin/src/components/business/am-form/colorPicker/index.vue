<template>
  <div class="am-color-picker">
    <el-color-picker
      v-model="colorValue"
      :predefine="predefinedColors"
      :disabled="disabled"
      :show-alpha="showAlpha"
      :size="size"
      @change="handleColorChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { ElColorPicker } from 'element-plus'

// 定义组件属性
const props = defineProps({
  modelValue: {
    type: String,
    default: '#5d87ff'
  },
  disabled: {
    type: Boolean,
    default: false
  },
  showAlpha: {
    type: Boolean,
    default: false
  },
  size: {
    type: String as () => 'large' | 'default' | 'small',
    default: 'default'
  },
  predefinedColors: {
    type: Array as () => string[],
    default: () => [
      '#5d87ff', // 主色调
      '#00b7ff', // 副色
      '#13deb9', // 成功色
      '#f59e0b', // 警告色
      '#ef4444', // 危险色
      '#8c8c8c', // 灰色
      '#722ed1', // 紫色
      '#13c2c2', // 青色
      '#fa8c16', // 橙色
      '#eb2f96', // 粉色
      '#597ef7', // 蓝色
      '#13c2c2', // 蓝绿色
      '#2f54eb', // 深蓝色
      '#fa541c', // 橙红色
      '#a0d911', // 黄绿色
      '#fadb14', // 黄色
      '#177ddc', // 天蓝色
      '#13c2c2', // 青色
      '#52c41a', // 绿色
      '#fadb14', // 浅黄色
    ]
  }
})

// 定义事件
const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'change', value: string): void
}>()

// 响应式颜色值
const colorValue = ref(props.modelValue)

// 监听外部值变化
watch(
  () => props.modelValue,
  (newValue) => {
    colorValue.value = newValue
  }
)

// 颜色变化处理
const handleColorChange = (value: any) => {
  emit('update:modelValue', value)
  emit('change', value)
}
</script>

<style scoped>
.am-color-picker {
  display: inline-block;
}
</style>