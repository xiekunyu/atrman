<!-- 侧边栏菜单树：递归渲染 useMenuStore().menuList，自行拼接绝对路径 -->
<template>
  <template v-for="item in visibleItems" :key="item.path || item.meta?.title">
    <!-- 含可见子菜单：渲染为可展开的 SubMenu（结构对齐基座 SidebarSubmenu） -->
    <ElSubMenu v-if="hasChildren(item)" :index="resolvePath(item)">
      <template #title>
        <div v-if="item.meta?.icon" class="menu-icon flex-cc">
          <ArtSvgIcon :icon="item.meta.icon" />
        </div>
        <span class="menu-name">{{ formatMenuTitle(item.meta?.title || '') }}</span>
      </template>
      <SidebarMenuTree :items="item.children || []" :base-path="resolvePath(item)" />
    </ElSubMenu>

    <!-- 叶子菜单：点击由 ElMenu 的 router 模式跳转到 index(绝对路径) -->
    <ElMenuItem v-else :index="resolvePath(item)">
      <div v-if="item.meta?.icon" class="menu-icon flex-cc">
        <ArtSvgIcon :icon="item.meta.icon" />
      </div>
      <template #title>
        <span class="menu-name">{{ formatMenuTitle(item.meta?.title || '') }}</span>
      </template>
    </ElMenuItem>
  </template>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import type { AppRouteRecord } from '@/types/router'
  import { formatMenuTitle } from '@/utils/router'

  // 递归组件需要显式 name 以自引用
  defineOptions({ name: 'SidebarMenuTree' })

  const props = withDefaults(
    defineProps<{
      /** 菜单数据（来自 store） */
      items?: AppRouteRecord[]
      /** 父级路径，用于把相对子路径拼接成绝对路径 */
      basePath?: string
    }>(),
    {
      items: () => [],
      basePath: ''
    }
  )

  /** 过滤掉隐藏菜单 */
  const visibleItems = computed(() => props.items.filter((item) => !item.meta?.isHide))

  /** 拼接绝对路径（子路由 path 可能是相对路径，如 'home' → '/manage/home'） */
  const resolvePath = (item: AppRouteRecord): string => {
    const path = item.path || ''
    if (!path) return props.basePath
    if (path.startsWith('/')) return path
    return `${props.basePath.replace(/\/$/, '')}/${path}`
  }

  /** 是否存在可见的子菜单 */
  const hasChildren = (item: AppRouteRecord): boolean => {
    return !!item.children?.some((child) => !child.meta?.isHide)
  }
</script>
