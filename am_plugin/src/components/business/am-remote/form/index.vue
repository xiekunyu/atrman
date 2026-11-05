<template>
    <template v-if="type ==='tree'">
      <el-tree-select
        v-model="modelValue"
        :data="options"
        v-bind="$attrs"
        :props="remoteStore.treeProps"
        :default-expanded-keys="[0]"
        check-strictly
        :disabled="disabled"
        :size="size"
        :multiple="multiple"
        :show-checkbox="multiple"
        :style="{ width }"
      />
    </template>
    <template v-else>
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
                :label="item.label"
                :value="item.value"
            />
        </el-select>
    </template>

</template>

<script setup lang="ts">
  import { useRemoteDataStore } from '@/store/modules/remoteData'

  defineOptions({ name: 'AmRemoteForm', inheritAttrs: false })

  interface Props {
    remote: string
    type?:  'select' | 'tree'
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
    type: 'select',
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

  const remoteStore = useRemoteDataStore()

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

  // 核心逻辑：使用 ref + watch 处理异步数据
  const options = ref<any[]>([])

  const fetchOptions = async () => {
    const list = await remoteStore.fetchOptions(props.remote, { tree: props.type === 'tree' }) || []
    if (props.type === 'tree') {
      options.value = list
    } else {
      // 如果没有指定 valueType，直接返回原始字典
      if (!props.valueType) {
        options.value = list
        return
      }
      // 如果指定了类型，进行映射转换
      options.value = list.map((item: any) => {
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
    }
  }

  // 监听 props 变化重新获取数据
  watch(() => [props.remote, props.type, props.valueType], fetchOptions, { immediate: true })
  
  onMounted(fetchOptions)
</script>
