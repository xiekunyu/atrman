<template>
  <el-popover
    v-model:visible="visible"
    placement="bottom-start"
    :width="480"
    trigger="click"
    popper-class="sa-icon-picker-popper"
  >
    <template #reference>
      <div
        class="icon-picker-trigger"
        @mouseenter="hovering = true"
        @mouseleave="hovering = false"
      >
        <el-input v-bind="$attrs" v-model="modelValue" readonly placeholder="点击选择图标">
          <template #prepend>
            <span class="selected-icon">
              <Icon v-if="modelValue" :icon="modelValue" />
              <el-icon v-else><Search /></el-icon>
            </span>
          </template>
        </el-input>

        <button
          v-if="hovering && modelValue && !disabled"
          class="clear-icon"
          type="button"
          @click.stop="handleClear"
        >
          <el-icon><CircleClose /></el-icon>
        </button>
      </div>
    </template>

    <div class="icon-picker-panel">
      <el-input
        v-model="searchText"
        class="search-input"
        placeholder="搜索图标（英文关键词）"
        clearable
        :prefix-icon="Search"
      />

      <div class="icon-scrollbar">
        <template v-if="searchText">
          <el-empty v-if="filteredIcons.length === 0" description="未找到相关图标" :image-size="80" />

          <div v-else class="icon-grid">
            <button
              v-for="icon in filteredIcons"
              :key="icon.name"
              class="icon-item"
              :class="{ 'is-active': modelValue === icon.name }"
              type="button"
              :title="icon.name"
              @click="handleSelect(icon.name)"
            >
              <Icon :icon="icon.name" />
            </button>
          </div>
        </template>

        <el-collapse v-else v-model="activeNames" class="icon-collapse">
          <el-collapse-item
            v-for="category in categories"
            :key="category.name"
            :title="category.name"
            :name="category.name"
          >
            <div class="icon-grid">
              <button
                v-for="icon in category.icons"
                :key="icon.name"
                class="icon-item"
                :class="{ 'is-active': modelValue === icon.name }"
                type="button"
                :title="icon.name"
                @click="handleSelect(icon.name)"
              >
                <Icon :icon="icon.name" />
              </button>
            </div>
          </el-collapse-item>
        </el-collapse>
      </div>
    </div>
  </el-popover>
</template>

<script lang="ts" setup>
  import { Search, CircleClose } from '@element-plus/icons-vue'
  import { Icon } from '@iconify/vue'

  import rawIcons from './lib/RemixIcon.json'

  defineOptions({ name: 'SaIconPicker', inheritAttrs: false })

  interface Props {
    disabled?: boolean
  }

  interface IconItem {
    name: string
    tags: string
  }

  interface Category {
    name: string
    icons: IconItem[]
  }

  withDefaults(defineProps<Props>(), {
    disabled: false
  })

  const modelValue = defineModel<string>()
  const visible = ref(false)
  const searchText = ref('')
  const hovering = ref(false)
  const activeNames = ref(['Arrows'])

  const allIcons: IconItem[] = []
  const categories: Category[] = Object.entries(rawIcons).map(([name, icons]) => {
    const categoryIcons = (icons as string[]).map((icon) => {
      const item = {
        name: `ri:${icon}`,
        tags: icon
      }

      allIcons.push(item)
      return item
    })

    return {
      name,
      icons: categoryIcons
    }
  })

  const filteredIcons = computed(() => {
    if (!searchText.value) return []

    const keyword = searchText.value.toLowerCase()
    return allIcons
      .filter((icon) => icon.name.toLowerCase().includes(keyword) || icon.tags.includes(keyword))
      .slice(0, 100)
  })

  const handleSelect = (icon: string) => {
    modelValue.value = icon
    visible.value = false
    searchText.value = ''
  }

  const handleClear = () => {
    modelValue.value = ''
  }
</script>

<style scoped lang="scss">
  .icon-picker-trigger {
    position: relative;
    width: 100%;
    cursor: pointer;
  }

  .selected-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    font-size: 18px;
    color: var(--el-text-color-regular);

    .el-icon {
      color: var(--el-text-color-placeholder);
    }
  }

  .clear-icon {
    position: absolute;
    top: 50%;
    right: 8px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    color: var(--el-text-color-placeholder);
    cursor: pointer;
    background: transparent;
    border: 0;
    transform: translateY(-50%);
    transition: color 0.2s ease;

    &:hover {
      color: var(--el-text-color-secondary);
    }
  }

  .icon-picker-panel {
    width: 100%;
  }

  .search-input {
    margin-bottom: 12px;
  }

  .icon-scrollbar {
    height: 300px;
    padding-right: 4px;
    overflow-y: auto;

    &::-webkit-scrollbar {
      width: 6px;
    }

    &::-webkit-scrollbar-thumb {
      background-color: var(--el-border-color);
      border-radius: 999px;
    }

    &::-webkit-scrollbar-track {
      background-color: transparent;
    }
  }

  .icon-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
  }

  .icon-item {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 44px;
    padding: 0;
    font-size: 24px;
    color: var(--el-text-color-regular);
    cursor: pointer;
    background: var(--el-fill-color-blank);
    border: 1px solid transparent;
    border-radius: 8px;
    transition:
      color 0.2s ease,
      border-color 0.2s ease,
      background-color 0.2s ease,
      transform 0.2s ease;

    &:hover {
      color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
      border-color: var(--el-color-primary-light-7);
      transform: translateY(-1px);
    }

    &.is-active {
      color: var(--el-color-primary);
      background: var(--el-color-primary-light-9);
      border-color: var(--el-color-primary-light-5);
    }
  }

  .icon-collapse {
    border-top: 0;
  }
</style>
