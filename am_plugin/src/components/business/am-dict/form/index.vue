<template>
    
    <template v-if="type === 'checkboxgroup'">
        <el-checkbox-group
            v-model="modelValue"
            v-bind="$attrs"
            :disabled="disabled"
            :size="size"
            :fill="fill"
            :text-color="textColor"
        >
            <!-- 模式1: 按钮样式 -->
            <template v-if="mode === 'button'">
            <el-checkbox-button v-if="allowNull" :value="nullValue" :label="nullLabel">
                {{ nullLabel }}
            </el-checkbox-button>
            <el-checkbox-button
                v-for="(item, index) in options"
                :key="index"
                :value="item.value"
                :label="item.name"
            >
                {{ item.name }}
            </el-checkbox-button>
            </template>

            <!-- 模式2: 普通/边框样式 -->
            <template v-else>
            <el-checkbox v-if="allowNull" :value="nullValue" :label="nullLabel" :border="mode === 'border'">
                {{ nullLabel }}
            </el-checkbox>
            <el-checkbox
                v-for="(item, index) in options"
                :key="index"
                :value="item.value"
                :label="item.name"
                :border="mode === 'border'"
            >
                {{ item.name }}
            </el-checkbox>
            </template>
        </el-checkbox-group>
    </template>
    <template v-else-if="type === 'select'">
        <el-select
            v-model="modelValue"
            v-bind="$attrs"
            :disabled="disabled"
            :size="size"
            :multiple="multiple"
            :style="{ width }"
        >
            <el-option v-if="allowNull" :value="nullValue" :label="nullLabel" />
            <el-option
                v-for="(item, index) in options"
                :key="index"
                :label="item.name"
                :value="item.value"
            />
        </el-select>
    </template>
    <template v-else>
        <el-radio-group
            v-model="modelValue"
            v-bind="$attrs"
            :disabled="disabled"
            :size="size"
            :fill="fill"
            :text-color="textColor"
        >
            <!-- 模式1: 按钮样式 -->
            <template v-if="mode === 'button'">
            <el-radio-button v-if="allowNull" :value="nullValue" :label="nullLabel">
                {{ nullLabel }}
            </el-radio-button>
            <el-radio-button
                v-for="(item, index) in options"
                :key="index"
                :value="item.value"
                :label="item.name"
            >
                {{ item.name }}
            </el-radio-button>
            </template>

            <!-- 模式2: 普通/边框样式 -->
            <template v-else>
            <el-radio v-if="allowNull" :value="nullValue" :label="nullLabel" :border="mode === 'border'">
                {{ nullLabel }}
            </el-radio>
            <el-radio
                v-for="(item, index) in options"
                :key="index"
                :value="item.value"
                :label="item.name"
                :border="mode === 'border'"
            >
                {{ item.name }}
            </el-radio>
            </template>
        </el-radio-group>
    </template>

</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useDictStore } from '@/store/modules/dict'

  defineOptions({ name: 'AmDictForm', inheritAttrs: false })

  interface Props {
    dict: string
    type?: 'radiogroup' | 'checkboxgroup' | 'select'
    mode?: 'normal' | 'border' | 'button'
    width?: string
    multiple?: boolean
    disabled?: boolean
    size?: 'large' | 'default' | 'small'
    fill?: string
    textColor?: string
    allowNull?: boolean
    nullValue?: string | number
    nullLabel?: string
    /**
     * 强制转换字典值的类型
     * 可选值: 'number' | 'string'
     * 默认使用 'number'
     */
    valueType?: 'number' | 'string'
  }

  const props = withDefaults(defineProps<Props>(), {
    type: 'radiogroup',
    mode: 'border',
    multiple: false,
    disabled: false,
    width: '100%',
    size: 'default',
    fill: '',
    textColor: '',
    allowNull: false,
    nullValue: '',
    nullLabel: '全部',
    valueType: 'number' // 默认不转换
  })

  // 这里支持泛型，保证外部接收到的类型是正确的
  const modelValue = defineModel<string | number | any>()

  const dictStore = useDictStore()

  // 判断能否转成数字
  const canConvertToNumberStrict = (value: any) => {
    // 严格模式：排除 null、undefined、布尔值、空数组等
    if (value == null) return false
    if (typeof value === 'boolean') return false
    if (Array.isArray(value) && value.length !== 1) return false
    if (typeof value === 'object' && !Array.isArray(value)) return false

    const num = Number(value)
    return !isNaN(num)
  }

  // 核心逻辑：在 computed 中处理数据类型转换
  const options = computed(() => {
    const list = dictStore.getByCode(props.dict) || []
    // 如果没有指定 valueType，直接返回原始字典
    if (!props.valueType) return list
    // 如果指定了类型，进行映射转换
    return list.map((item) => {
      let newValue = item.value as string | number

      switch (props.valueType) {
        case 'number':
          if (canConvertToNumberStrict(item.value)) {
            newValue = Number(item.value)
          }
          break
        case 'string':
          newValue = String(item.value)
          break
      }

      return {
        ...item,
        value: newValue
      }
    })
  })
</script>
