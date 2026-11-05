<!-- 应用市场升级确认弹窗：视觉对齐「系统与升级」详情卡片（ElAlert + kv-list + 分区标题） -->
<template>
  <ElDialog
    :model-value="state.visible"
    :title="dialogTitle"
    width="580px"
    align-center
    @update:model-value="handleVisibleChange"
  >
    <template v-if="preflight">
      <!-- 阻断模式：不兼容错误提示置顶，且不提供继续升级出口 -->
      <ElAlert
        v-if="state.blockMode"
        type="error"
        show-icon
        :closable="false"
        class="upgrade-dialog__alert"
        :title="`v${preflight.target_version} 无法安装到当前基座`"
        :description="incompatDescription"
      />

      <!-- 1. 版本流程：当前版本 → 升级版本（一行内联） -->
      <div class="version-flow">
        <span class="version-flow__label">当前版本</span>
        <span class="version-flow__no">v{{ preflight.current_version }}</span>
        <ArtSvgIcon icon="ri:arrow-right-line" class="version-flow__arrow" />
        <span class="version-flow__label">升级版本</span>
        <span class="version-flow__no version-flow__no--target">
          v{{ preflight.target_version }}
        </span>
      </div>

      <!-- 2. 包大小 / 发布时间：紧凑一行小字 -->
      <div class="meta-line">
        <span><b>安装包大小：</b>{{ formatPackageSize(preflight.package_size) }}</span>
        <span v-if="preflight.release_date"><b>发布时间：</b>{{ preflight.release_date }}</span>
      </div>

      <!-- 3. 更新内容 -->
      <div class="section">
        <div class="section__title">更新内容</div>
        <div class="changelog-box">{{ preflight.changelog || '暂无更新日志' }}</div>
      </div>

      <!-- 4. 短暂不可用提示：可升级时固定展示，级联时追加停用范围；
           不兼容场景的兼容提示已由顶部 error 告警承载，不再单独占区 -->
      <ElAlert
        v-if="!state.blockMode"
        type="warning"
        show-icon
        :closable="false"
        class="upgrade-dialog__alert"
        title="升级过程中应用将短暂不可用，升级完成后自动恢复，请避开业务高峰期操作。"
      >
        <template v-if="state.cascade && preflight.will_disable?.length" #default>
          <div class="disable-tip">
            <div>本次升级将临时停用以下插件，成功后自动恢复：</div>
            <div class="disable-tip__tags">
              <ElTag
                v-for="item in preflight.will_disable"
                :key="item.name"
                size="small"
                type="warning"
                effect="plain"
              >
                {{ item.title || item.name }}
              </ElTag>
            </div>
          </div>
        </template>
      </ElAlert>
    </template>

    <template #footer>
      <ElButton @click="handleCancel">
        {{ state.blockMode ? '我知道了' : '取 消' }}
      </ElButton>
      <ElButton v-if="!state.blockMode" type="primary" @click="handleConfirm">确定升级</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import type { StoreUpdatePreflight } from '@/api/plugin/store'
  import { formatPackageSize, resolveUpgradeDialog, upgradeDialogState } from '../storeUtils'

  defineOptions({ name: 'StoreUpgradeDialog' })

  const state = upgradeDialogState

  const preflight = computed<StoreUpdatePreflight | null>(() => state.preflight)

  const dialogTitle = computed(() => {
    if (state.blockMode) return '版本不兼容'
    return '升级确认';
  })

  const incompatDescription = computed(() => {
    const pf = preflight.value
    if (!pf) return ''
    const message = pf.compatibility_message || '该版本不兼容当前基座。'
    return `${message}请先在「系统与升级」中将基座升级到要求版本，再更新本应用。`
  })

  function handleVisibleChange(visible: boolean) {
    if (!visible) handleCancel()
  }

  function handleCancel() {
    resolveUpgradeDialog(false)
  }

  function handleConfirm() {
    resolveUpgradeDialog(true)
  }
</script>

<style scoped lang="scss">
  .upgrade-dialog__alert {
    margin-bottom: 16px;
  }

  /* 版本流程：当前 → 目标，一行内联文字 */
  .version-flow {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    font-size: 13px;
  }

  .version-flow__label {
    color: var(--art-gray-500, #909399);
  }

  .version-flow__no {
    font-weight: 600;
    color: var(--art-gray-800, #303133);
  }

  .version-flow__no--target {
    color: var(--el-color-primary);
  }

  .version-flow__arrow {
    flex-shrink: 0;
    margin: 0 4px;
    font-size: 16px;
    color: var(--el-color-primary);
  }

  /* 元信息：包大小 / 发布时间，一行小字 */
  .meta-line {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 14px;
    font-size: 12px;
    color: var(--art-gray-600, #606266);

    em {
      margin-right: 4px;
      font-style: normal;
      color: var(--art-gray-500, #909399);
    }
  }

  .section {
    margin-bottom: 16px;
  }

  .section__title {
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 600;
    color: var(--art-gray-800, #303133);
  }

  /* 更新内容：pre-line 保留换行，不再拼装 HTML */
  .changelog-box {
    max-height: 200px;
    padding: 10px 12px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.8;
    color: var(--art-gray-700, #606266);
    white-space: pre-line;
    word-break: break-word;
    background: var(--el-fill-color-lighter);
    border-radius: 8px;
  }

  .disable-tip {
    font-size: 12px;
    line-height: 1.7;

    &__tags {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 6px;
    }
  }
</style>
