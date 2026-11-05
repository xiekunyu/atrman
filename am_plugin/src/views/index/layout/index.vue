<!-- 前台公开入口布局：无侧栏/无菜单的裸容器，与后台管理布局（views/manage）完全独立 -->
<template>
  <div class="plugin-index-layout" :class="{ 'is-embedded': isEmbedded }">
    <!-- 极简页头（品牌名 + 导航 + 会员态区），不需要可直接删除 -->
    <header id="plugin-index-header">
      <div class="index-brand" @click="router.push(INDEX_HOME_PATH)">
        <span class="brand-title">{{ appName }}</span>
      </div>
      <nav class="index-nav">
        <RouterLink :to="INDEX_HOME_PATH">首页</RouterLink>
      </nav>

      <!--
        会员态区（统一对接 am_member 会员中心，插件不自建登录/注册页）：
        - 未登录：登录/注册文字按钮 → 同页签整页跳会员中心，完成后票据闭环自动回到当前页；
        - 已登录：头像+昵称下拉，个人资料新标签打开会员中心，退出仅清本地会话（同域各端 storage 同步）。
      -->
      <div class="index-user">
        <template v-if="userStore.isLogin">
          <ElDropdown trigger="click" @command="onUserCommand">
            <span class="user-trigger">
              <ElAvatar :size="28" :src="avatarUrl">
                {{ displayName.charAt(0).toUpperCase() }}
              </ElAvatar>
              <span class="user-name">{{ displayName }}</span>
              <ElIcon class="user-caret"><ArrowDown /></ElIcon>
            </span>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="member">
                  <ElIcon><User /></ElIcon>会员资料
                </ElDropdownItem>
                <ElDropdownItem command="logout" divided>
                  <ElIcon><SwitchButton /></ElIcon>退出登录
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>
        </template>
        <template v-else>
          <button type="button" class="btn-text" @click="openMemberLogin({ redirect: currentHashPath() })">
            登录
          </button>
          <button type="button" class="btn-text btn-text--primary" @click="openMemberRegister({ redirect: currentHashPath() })">
            注册
          </button>
        </template>
      </div>
    </header>

    <!-- 内容区：前台页面自行控制滚动与高度 -->
    <main id="plugin-index-main">
      <router-view />
    </main>

    <footer id="plugin-index-footer">
      <span>Powered by {{ appName }}</span>
    </footer>
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElAvatar, ElDropdown, ElDropdownMenu, ElDropdownItem, ElIcon, ElMessageBox } from 'element-plus'
  import { ArrowDown, User, SwitchButton } from '@element-plus/icons-vue'
  import AppConfig from '@/config'
  import { hasBridge } from '@/sdk/bridge'
  import { openMemberLogin, openMemberRegister, openMemberCenter, logoutLocal, currentHashPath } from '@/sdk/member'
  import { useUserStore } from '@/store/modules/user'
  import { INDEX_HOME_PATH } from '@/router/modules/index'

  defineOptions({ name: 'IndexLayout' })

  const router = useRouter()

  // 嵌入基座(qiankun)时高度需扣除基座头部占用；直连公开访问时占满视口
  const isEmbedded = hasBridge()
  const appName = computed(() => AppConfig.systemInfo.name)

  const userStore = useUserStore()
  const displayName = computed(
    () => userStore.info?.nickname || userStore.info?.username || '会员用户'
  )
  const avatarUrl = computed(() => (userStore.info?.avatar as string) || '')

  async function onUserCommand(command: string) {
    if (command === 'member') {
      // 个人资料/账号安全只在会员中心提供：跨应用浏览，新标签打开无需自动回流
      openMemberCenter()
    } else if (command === 'logout') {
      try {
        await ElMessageBox.confirm('确定退出登录吗？', '提示', {
          confirmButtonText: '退出',
          cancelButtonText: '取消',
          type: 'warning'
        })
      } catch {
        return
      }
      // 仅清本地统一会话；同域其它标签经 storage 事件自动同步登出
      logoutLocal()
    }
  }
</script>

<style lang="scss" scoped>
  // 类名/ID 统一 plugin-index- 前缀，避免与基座、后台布局结构碰撞
  .plugin-index-layout {
    --plugin-index-height: 100vh;

    display: flex;
    box-sizing: border-box;
    width: 100%;
    height: var(--plugin-index-height);
    overflow: hidden;
    flex-direction: column;
    background: var(--default-bg-color);
    color: var(--el-text-color-primary);

    &.is-embedded {
      --plugin-index-height: calc(100vh - 120px);
    }

    #plugin-index-header {
      display: flex;
      flex-shrink: 0;
      gap: 24px;
      align-items: center;
      height: 56px;
      padding: 0 24px;
      border-bottom: 1px solid var(--art-card-border);
      background: var(--default-box-color);

      .index-brand {
        display: flex;
        gap: 8px;
        align-items: center;
        cursor: pointer;

        .brand-title {
          font-size: 16px;
          font-weight: 600;
          white-space: nowrap;
        }
      }

      .index-nav {
        display: flex;
        gap: 16px;
        font-size: 14px;

        a {
          color: var(--el-text-color-regular);
          text-decoration: none;

          &.router-link-active,
          &:hover {
            color: var(--theme-color, var(--el-color-primary));
          }
        }
      }

      .index-user {
        display: flex;
        gap: 12px;
        align-items: center;
        margin-left: auto;
        font-size: 14px;

        .user-trigger {
          display: flex;
          gap: 8px;
          align-items: center;
          padding: 4px 8px;
          cursor: pointer;
          border-radius: 6px;
          outline: none;

          &:hover,
          &:focus {
            background: var(--el-fill-color-light);
          }

          .user-name {
            max-width: 140px;
            overflow: hidden;
            color: var(--el-text-color-primary);
            text-overflow: ellipsis;
            white-space: nowrap;
          }

          .user-caret {
            font-size: 12px;
            color: var(--el-text-color-secondary);
          }
        }

        .btn-text {
          padding: 4px 8px;
          font-size: 14px;
          color: var(--el-text-color-regular);
          cursor: pointer;
          background: none;
          border: none;

          &:hover {
            color: var(--theme-color, var(--el-color-primary));
          }

          &--primary {
            font-weight: 500;
            color: var(--theme-color, var(--el-color-primary));
          }
        }
      }
    }

    #plugin-index-main {
      flex: 1;
      box-sizing: border-box;
      min-height: 0;
      overflow: auto;
    }

    #plugin-index-footer {
      display: flex;
      flex-shrink: 0;
      align-items: center;
      justify-content: center;
      height: 44px;
      font-size: 12px;
      color: var(--el-text-color-secondary);
      border-top: 1px solid var(--art-card-border);
    }
  }

  @media only screen and (width <= 1180px) {
    .plugin-index-layout:not(.is-embedded) {
      --plugin-index-height: 100dvh;
    }
  }
</style>
