<!--
  超管后台全局升级提醒
  登录后（后台布局挂载时）检测主程序是否有新版本，弹窗告知版本号与更新内容。
  仅超级管理员可见；提供「不再提醒（当前版本）/ 稍后提醒（3 小时后）/ 去更新（进系统信息页）」三种选择。
  提醒状态以目标版本为键写入 localStorage：升级后版本变化或已无更新则不再打扰。
-->
<template>
  <ElDialog
    v-model="visible"
    title="发现新版本"
    width="460px"
    align-center
    append-to-body
    :close-on-click-modal="false"
  >
    <div class="upgrade-notice">
      <div class="upgrade-notice__head">
        <ArtSvgIcon icon="ri:rocket-2-line" class="upgrade-notice__icon" />
        <div class="upgrade-notice__lead">
          <p class="upgrade-notice__title">主程序 v{{ targetVersion }} 可升级</p>
          <p class="upgrade-notice__sub">当前 v{{ currentVersion || '-' }} → 目标 v{{ targetVersion }}</p>
        </div>
      </div>
      <div v-if="changelog.length" class="upgrade-notice__log">
        <p class="upgrade-notice__log-title">更新内容</p>
        <ul>
          <li v-for="(line, i) in changelog" :key="i">{{ line }}</li>
        </ul>
      </div>
      <p v-else class="upgrade-notice__log-empty">（该版本未提供更新说明）</p>
    </div>
    <template #footer>
      <ElButton text @click="onMute">不再提醒</ElButton>
      <ElButton @click="onSnooze">稍后提醒</ElButton>
      <ElButton type="primary" @click="goUpdate">去更新</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted, onUnmounted } from 'vue'
  import { useRouter } from 'vue-router'
  import { useUserStore } from '@/store/modules/user'
  import upgradeApi from '@/api/system/upgrade'

  defineOptions({ name: 'ArtUpgradeNotice' })

  const router = useRouter()
  const userStore = useUserStore()
  const isSuperAdmin = computed(() => Boolean(userStore.getUserInfo?.is_admin))

  const visible = ref(false)
  const currentVersion = ref('')
  const targetVersion = ref('')
  const changelog = ref<string[]>([])

  /** 提醒状态存储键（以目标版本为维度）与稍后提醒间隔（3 小时） */
  const STORE_KEY = 'atrman:sys-upgrade-notice'
  const SNOOZE_MS = 3 * 60 * 60 * 1000
  const SYSTEM_INFO_PATH = '/system/info'
  let snoozeTimer: ReturnType<typeof setTimeout> | null = null

  type NoticeState = { version: string; action: 'mute' | 'snooze'; ts: number }

  const readState = (): NoticeState | null => {
    try {
      const raw = localStorage.getItem(STORE_KEY)
      const data = raw ? JSON.parse(raw) : null
      return data && typeof data.version === 'string' ? (data as NoticeState) : null
    } catch {
      return null
    }
  }

  const writeState = (s: NoticeState): void => {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify(s))
    } catch {
      // localStorage 不可用（隐私模式等）时忽略，退化为每次登录都提醒
    }
  }

  const clearSnoozeTimer = (): void => {
    if (snoozeTimer) {
      clearTimeout(snoozeTimer)
      snoozeTimer = null
    }
  }

  /** 检测升级并按提醒策略决定是否弹窗 */
  async function check(): Promise<void> {
    if (!isSuperAdmin.value) return
    // 已在系统信息页则不打扰（用户本就在看升级入口）
    if (router.currentRoute.value.path === SYSTEM_INFO_PATH) return
    try {
      const data: any = await upgradeApi.plan()
      const plan = data || {}
      if (!plan.has_update) {
        visible.value = false
        return
      }
      const target = String(plan.latest_version || '')
      if (!target) return

      currentVersion.value = String(plan.current_version || '')
      targetVersion.value = target
      // 后端一条日志对应一个包，内部仍带换行；HTML 会折叠成一句，逐行拆开后每行一个条目
      changelog.value = (Array.isArray(plan?.summary?.changelog) ? plan.summary.changelog : [])
        .flatMap((text: unknown) => String(text ?? '').split(/\r?\n/))
        .map((line: string) => line.trim())
        .filter(Boolean)

      const st = readState()
      if (st && st.version === target) {
        if (st.action === 'mute') return // 该版本已选择不再提醒
        if (st.action === 'snooze') {
          const elapsed = Date.now() - st.ts
          if (elapsed < SNOOZE_MS) {
            // 稍后提醒：到剩余时间再查（届时若已升级，has_update=false 自然不再弹）
            clearSnoozeTimer()
            snoozeTimer = setTimeout(check, SNOOZE_MS - elapsed)
            return
          }
        }
      }
      visible.value = true
    } catch {
      // 检查失败静默：升级提醒不得阻断登录
    }
  }

  function onMute(): void {
    writeState({ version: targetVersion.value, action: 'mute', ts: Date.now() })
    visible.value = false
  }

  function onSnooze(): void {
    writeState({ version: targetVersion.value, action: 'snooze', ts: Date.now() })
    visible.value = false
    clearSnoozeTimer()
    snoozeTimer = setTimeout(check, SNOOZE_MS)
  }

  function goUpdate(): void {
    visible.value = false
    router.push(SYSTEM_INFO_PATH)
  }

  onMounted(check)
  onUnmounted(clearSnoozeTimer)
</script>

<style scoped lang="scss">
  .upgrade-notice {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .upgrade-notice__head {
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .upgrade-notice__icon {
    flex-shrink: 0;
    font-size: 40px;
    color: var(--el-color-primary);
  }

  .upgrade-notice__lead {
    min-width: 0;
  }

  .upgrade-notice__title {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--art-text-gray-900);
  }

  .upgrade-notice__sub {
    margin: 4px 0 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .upgrade-notice__log {
    padding: 10px 12px;
    background: var(--art-gray-100);
    border-radius: 8px;
  }

  .upgrade-notice__log-title {
    margin: 0 0 6px;
    font-size: 12px;
    color: var(--art-gray-600);
  }

  .upgrade-notice__log ul {
    max-height: 180px;
    margin: 0;
    padding-left: 18px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.7;
    color: var(--art-text-gray-800);
  }

  .upgrade-notice__log-empty {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
</style>
