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

  const methodOptions = [
    { label: 'POST', value: 'POST' },
    { label: 'PUT', value: 'PUT' },
    { label: 'DELETE', value: 'DELETE' }
  ]

  const formItems = computed(() => [
    {
      label: '关键词',
      key: 'keywords',
      type: 'input',
      props: { placeholder: '用户名/业务/路由/IP', clearable: true }
    },
    {
      label: '用户名',
      key: 'username',
      type: 'input',
      props: { placeholder: '请输入用户名', clearable: true }
    },
    {
      label: '业务名称',
      key: 'business_name',
      type: 'input',
      props: { placeholder: '请输入业务名称', clearable: true }
    },
    {
      label: '请求方式',
      key: 'method',
      type: 'select',
      props: {
        placeholder: '请选择请求方式',
        options: methodOptions,
        clearable: true
      }
    },
    {
      label: '操作时间',
      key: 'create_time',
      type: 'datetime',
      span: 12,
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
