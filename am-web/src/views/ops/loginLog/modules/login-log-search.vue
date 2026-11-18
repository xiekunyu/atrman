<template>
  <ArtSearchBar
    ref="searchBarRef"
    v-model="formData"
    :items="formItems"
    :rules="rules"
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
    set: (val) => emit('update:modelValue', val)
  })

  const rules = {}

  const statusOptions = [
    { label: '成功', value: 1 },
    { label: '失败', value: 2 }
  ]

  const formItems = computed(() => [
    {
      label: '关键词',
      key: 'keywords',
      type: 'input',
      props: { placeholder: '用户名/IP/归属地/消息', clearable: true }
    },
    {
      label: '用户名',
      key: 'username',
      type: 'input',
      props: { placeholder: '请输入用户名', clearable: true }
    },
    {
      label: '登录IP',
      key: 'ip',
      type: 'input',
      props: { placeholder: '请输入登录IP', clearable: true }
    },
    {
      label: '登录状态',
      key: 'status',
      type: 'select',
      props: {
        placeholder: '请选择登录状态',
        options: statusOptions,
        clearable: true
      }
    },
    {
      label: '登录时间',
      key: 'create_time',
      type: 'datetime',
      props: {
        style: { width: '100%' },
        type: 'datetimerange',
        rangeSeparator: '至',
        startPlaceholder: '开始时间',
        endPlaceholder: '结束时间',
        valueFormat: 'YYYY-MM-DD HH:mm:ss'
      }
    }
  ])

  const handleReset = () => {
    emit('reset')
  }

  const handleSearch = async () => {
    await searchBarRef.value.validate()
    emit('search', formData.value)
  }
</script>
