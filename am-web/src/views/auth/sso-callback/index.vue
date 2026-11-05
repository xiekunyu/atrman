<template>
  <div class="sso-callback">
    <ElIcon v-if="!error" class="is-loading" :size="28"><Loading /></ElIcon>
    <p>{{ error || '正在完成登录…' }}</p>
  </div>
</template>

<script setup lang="ts">
  import { onMounted, ref } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { Loading } from '@element-plus/icons-vue'
  import { ElIcon } from 'element-plus'
  import { fetchSsoExchange } from '@/api/auth-sso'
  import { useUserStore } from '@/store/modules/user'
  import { writeSession } from '@/utils/atrman-session'

  defineOptions({ name: 'SsoCallback' })

  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()
  const error = ref('')

  /**
   * 解析安全回跳地址：仅放行「同源相对路径」或「同源绝对地址」，防开放重定向
   * （与后端 SsoService::validateRedirect 白名单形成双重保险）。
   * - spa：站内 hash 路由 → router.replace（不整页刷新，令牌已在 store）
   * - page：同源绝对地址（如后端 /oauth/authorize）→ 整页替换，交由后端处理
   * - home：非法/缺失 → 回落首页
   */
  function resolveRedirect(raw: string): { mode: 'spa' | 'page' | 'home'; target: string } {
    const fallback = { mode: 'home' as const, target: '/' }
    if (!raw) return fallback
    if (/^https?:\/\//i.test(raw)) {
      try {
        const u = new URL(raw)
        if (u.origin !== window.location.origin) return fallback
        return { mode: 'page', target: u.pathname + u.search + u.hash }
      } catch {
        return fallback
      }
    }
    if (!raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\') || raw.includes('\\')) {
      return fallback
    }
    // 后端端点路径（如 OAuth 授权 /oauth、插件入口 /app）非 am-web SPA 路由，整页回跳交后端处理
    if (/^\/(oauth|app)(\/|\?|$)/i.test(raw)) {
      return { mode: 'page', target: raw }
    }
    return { mode: 'spa', target: raw }
  }

  // 兑换失败/凭证缺失时引导重新登录；仅对站内相对路径保留 redirect，绝对后端地址不带入原生登录
  function goLogin(raw: string) {
    const { mode, target } = resolveRedirect(raw)
    router.replace({
      name: 'Login',
      query: mode === 'spa' && target && target !== '/' ? { redirect: target } : undefined
    })
  }

  onMounted(async () => {
    const ticket = String(route.query.ticket || '')
    const redirect = String(route.query.redirect || '/')
    if (!ticket) {
      error.value = '登录凭证缺失'
      goLogin(redirect)
      return
    }
    try {
      // 票据一次性，兑换成功后立即写入登录态；刷新本页会因票据已用而失败
      const res = await fetchSsoExchange(ticket)
      userStore.setToken(res.accessToken, res.refreshToken)
      userStore.setLoginStatus(true)
      userStore.setUserInfo(res.user)
      // 写入跨端统一会话契约：同域前台插件本地优先可直接复用此会话免登
      writeSession({ accessToken: res.accessToken, refreshToken: res.refreshToken, user: res.user })
      const { mode, target } = resolveRedirect(redirect)
      if (mode === 'page') window.location.replace(target)
      else router.replace(target)
    } catch {
      error.value = '登录已失效或票据已使用，正在前往登录…'
      setTimeout(() => goLogin(redirect), 1200)
    }
  })
</script>

<style scoped>
  .sso-callback {
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
    justify-content: center;
    height: 100vh;
    color: var(--el-text-color-secondary);
  }
</style>
