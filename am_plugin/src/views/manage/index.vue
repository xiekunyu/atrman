<!-- 后台管理布局容器：自写左右布局（左侧菜单 + 右侧内容），菜单数据仍来自 useMenuStore -->
<template>
  <div class="plugin-layout" :class="{ 'is-embedded': isEmbedded }">
    <!-- 左侧菜单 -->
    <aside id="plugin-sidebar" :class="{ 'is-collapse': collapsed }">

      <ElScrollbar class="sidebar-scroll">
        <ElMenu
          :default-active="activeMenu"
          :collapse="collapsed"
          :collapse-transition="false"
          :unique-opened="true"
          router
        >
          <SidebarMenuTree :items="menuList" base-path="" />
        </ElMenu>
      </ElScrollbar>

      <div class="sidebar-collapse" @click="collapsed = !collapsed">
        <ArtSvgIcon :icon="collapsed ? 'ri:menu-fold-2-line' : 'ri:menu-unfold-2-line'" />
      </div>
    </aside>

    <!-- 右侧内容 -->
    <main id="plugin-main">
      <div id="plugin-content">
        <router-view v-slot="{ Component }">
          <KeepAlive>
            <component :is="Component" />
          </KeepAlive>
        </router-view>
      </div>
    </main>

    <ArtGlobalComponent />
  </div>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { useMenuStore } from '@/store/modules/menu'
  import AppConfig from '@/config'
  import { hasBridge } from '@/sdk/bridge'
  import SidebarMenuTree from './SidebarMenuTree.vue'

  defineOptions({ name: 'AppLayout' })

  const route = useRoute()
  const router = useRouter()
  const menuStore = useMenuStore()

  // 是否嵌入基座(qiankun)运行：嵌入时高度 = calc(100vh - 92px)，独立预览时 = 100vh
  const isEmbedded = hasBridge()

  const collapsed = ref(true)
  const appName = AppConfig.systemInfo.name
  const menuList = computed(() => menuStore.menuList)
  const activeMenu = computed(() => route.path)

  /** 点击 logo 回到首页 */
  const goHome = () => {
    router.push(menuStore.getHomePath() || '/manage/home')
  }
</script>

<style lang="scss" scoped>
  @use './style';
</style>
