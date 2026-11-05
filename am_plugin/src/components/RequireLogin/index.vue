<!--
  RequireLogin 登录门控组件（前台公开入口专用）

  插件没有独立登录页：未登录时不跳走、不白屏，就地展示登录引导；
  登录/注册整页跳会员中心（同页签票据闭环），完成后自动回到当前 hash 深链。

  用法：
    <RequireLogin>
      <MyMemberContent />
    </RequireLogin>

    自定义文案：
    <RequireLogin title="登录后查看我的订单" description="仅会员可见">
      ...
    </RequireLogin>

    内联模式（嵌入卡片，不占整屏）：
    <RequireLogin inline>...</RequireLogin>

  约定：路由守卫不消费 meta.requiresAuth（front 不做自动跳转），页面级门控一律用本组件，
  动作级守门用 requireLogin()。收到 401 事件后本组件自动从未登录态翻牌，无需刷新页面。
-->
<template>
  <slot v-if="isLogin" />
  <div v-else class="require-login" :class="{ 'require-login--inline': inline }">
    <ElEmpty :description="''" :image-size="inline ? 72 : 120">
      <template #image>
        <div class="require-login__icon">
          <ElIcon :size="inline ? 30 : 44"><User /></ElIcon>
        </div>
      </template>
      <div class="require-login__title">{{ title }}</div>
      <div v-if="description" class="require-login__desc">{{ description }}</div>
      <div class="require-login__actions">
        <ElButton type="primary" :icon="User" @click="goLogin">登 录</ElButton>
        <ElButton :icon="EditPen" @click="goRegister">免费注册</ElButton>
        <ElButton link type="primary" :loading="reloading" @click="reload">
          我已在其他标签完成登录
        </ElButton>
      </div>
    </ElEmpty>
  </div>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
  import { ElButton, ElEmpty, ElIcon, ElMessage } from 'element-plus'
  import { User, EditPen } from '@element-plus/icons-vue'
  import { useUserStore } from '@/store/modules/user'
  import { openMemberLogin, openMemberRegister, onUnauthorized, currentHashPath } from '@/sdk/member'

  defineOptions({ name: 'RequireLogin' })

  withDefaults(
    defineProps<{
      /** 未登录时的标题 */
      title?: string
      /** 补充说明 */
      description?: string
      /** 内联模式：更小留白，可嵌入卡片 */
      inline?: boolean
    }>(),
    {
      title: '该内容需要登录后查看',
      description: '登录将跳转到会员中心，完成后自动返回当前页面',
      inline: false
    }
  )

  const userStore = useUserStore()
  const isLogin = computed(() => userStore.isLogin)
  const reloading = ref(false)

  // 401 事件触发时 isLogin 已被 handleSessionExpired 置 false，computed 自动翻牌；
  // 订阅仅为保留"未来需要在翻牌瞬间做点什么"的挂点（并防止 tree-shaking 丢依赖）。
  let off: (() => void) | undefined
  onMounted(() => {
    off = onUnauthorized(() => {
      /* 登录态由 store 驱动，此处无需额外处理 */
    })
  })
  onBeforeUnmount(() => off?.())

  function goLogin() {
    openMemberLogin({ redirect: currentHashPath() })
  }

  function goRegister() {
    openMemberRegister({ redirect: currentHashPath() })
  }

  // 跨标签登录的 storage 同步理论上会自动水合；手动刷新用于兜底极端竞态
  async function reload() {
    reloading.value = true
    try {
      window.location.reload()
    } catch {
      ElMessage.warning('刷新失败，请手动刷新页面')
      reloading.value = false
    }
  }
</script>

<style scoped>
  .require-login {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 16px;
  }

  .require-login--inline {
    padding: 24px 12px;
  }

  .require-login__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 88px;
    height: 88px;
    border-radius: 50%;
    color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }

  .require-login--inline .require-login__icon {
    width: 60px;
    height: 60px;
  }

  .require-login__title {
    margin-top: 12px;
    font-size: 16px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .require-login__desc {
    margin-top: 6px;
    font-size: 13px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
  }

  .require-login__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    align-items: center;
    justify-content: center;
    margin-top: 18px;
  }
</style>
