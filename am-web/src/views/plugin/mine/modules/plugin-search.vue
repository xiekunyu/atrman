<template>
  <ArtSearchBar
    ref="searchBarRef"
    v-model="formData"
    :items="formItems"
    :span="6"
    :show-expand="false"
    @reset="handleReset"
    @search="handleSearch"
  />
</template>

<script setup lang="ts">
  interface Props {
    modelValue: Record<string, any>
  }

  interface Emits {
    (e: 'update:modelValue', value: Record<string, any>): void
    (e: 'search', params: Record<string, any>): void
    (e: 'reset'): void
  }

  const props = defineProps<Props>()
  const emit = defineEmits<Emits>()
  const searchBarRef = ref()

  const formData = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
  })

  const formItems = computed(() => [
    {
      label: '关键词',
      key: 'keywords',
      type: 'input',
      props: {
        placeholder: '搜索名称、标识、描述',
        clearable: true
      }
    },
    {
      label: '状态',
      key: 'status',
      type: 'select',
      props: {
        placeholder: '全部状态',
        clearable: true,
        options: [
          { label: '异常', value: -1 },
          { label: '已禁用', value: 0 },
          { label: '已启用', value: 1 },
          { label: '未安装', value: 2 }
        ]
      }
    },
    {
      label: '应用类型',
      key: 'is_custom',
      type: 'select',
      props: {
        placeholder: '全部类型',
        clearable: true,
        options: [
          { label: '基础应用', value: 0 },
          { label: '自建应用', value: 1 }
        ]
      }
    },
    {
      label: '开发模式',
      key: 'is_dev',
      type: 'select',
      props: {
        placeholder: '全部模式',
        clearable: true,
        options: [
          { label: '开发模式', value: 1 },
          { label: '非开发模式', value: 0 }
        ]
      }
    }
  ])

  const handleSearch = (params: Record<string, any>) => {
    emit('search', params)
  }

  const handleReset = () => {
    emit('reset')
  }
</script>
