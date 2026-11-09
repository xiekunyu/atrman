/**
 * 用户状态 Composable
 *
 * 插件通过 qiankun 加载时，基座已登录。
 * 本模块在 mount 阶段自动从基座获取当前用户信息并缓存，
 * 插件页面通过 useUser() 直接读取，无需二次登录。
 *
 * @example
 * ```vue
 * <script setup lang="ts">
 * import { useUser } from '@/sdk/user'
 * const { user, loading } = useUser()
 * </script>
 *
 * <template>
 *   <div v-if="!loading">
 *     <p>当前用户：{{ user?.nickname }}</p>
 *   </div>
 * </template>
 * ```
 */
import { ref, readonly, type DeepReadonly, type Ref } from 'vue'
import { invoke } from './bridge'

/** 用户信息类型 */
export interface PluginUserInfo {
  id?: number
  sid?: string
  username?: string
  nickname?: string
  avatar?: string
  account_type?: number
  is_admin?: boolean
  accessToken?: string
  [key: string]: any
}

// 模块级单例：整个插件共享同一份用户状态
const user: Ref<PluginUserInfo | null> = ref(null)
const loading = ref(false)
let initialized = false

/**
 * 初始化用户信息（由 qiankun mount 自动调用）
 *
 * 从基座 Bridge 获取当前登录用户的资料和会话信息。
 * 多次调用只会请求一次（缓存机制）。
 */
export const initUser = async (): Promise<PluginUserInfo | null> => {
  if (initialized) return user.value
  loading.value = true
  try {
    const [profile, session] = await Promise.all([
      invoke<PluginUserInfo>('auth.getUserProfile').catch(() => ({})),
      invoke<{ accessToken?: string }>('auth.getSession').catch(() => ({}))
    ])
    user.value = { ...(profile || {}), ...(session || {}) }
    initialized = true
    return user.value
  } catch (e) {
    console.warn('[Plugin SDK] 获取用户信息失败：', e)
    return null
  } finally {
    loading.value = false
  }
}

/**
 * 获取当前用户信息（响应式）
 *
 * 返回只读的响应式引用，数据在 mount 时已自动加载。
 * 如果需要在页面中手动刷新用户信息，可调用 refreshUser()。
 */
export const useUser = () => {
  return {
    user: readonly(user) as DeepReadonly<Ref<PluginUserInfo | null>>,
    loading: readonly(loading)
  }
}

/**
 * 手动刷新用户信息
 *
 * 一般不需要调用，除非用户信息在基座侧发生了变化需要重新获取。
 */
export const refreshUser = async (): Promise<PluginUserInfo | null> => {
  initialized = false
  return initUser()
}

/** 重置用户状态（由 unmount 调用） */
export const resetUser = () => {
  user.value = null
  loading.value = false
  initialized = false
}
