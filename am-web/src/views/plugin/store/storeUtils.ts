/**
 * 应用商店共享工具
 *
 * 提取 index.vue 与 detail.vue 共用的分类图标、背景色、价格格式化，以及
 * 升级预检/级联确认/结果呈现流程，避免两个组件间重复定义。
 */

import { reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import storeApi, { type StoreUpdatePreflight, type StoreCascadeResult } from '@/api/plugin/store'

/** 分类图标映射 */
export const getCategoryIcon = (category: string): string => {
  const iconMap: Record<string, string> = {
    business: 'ri:briefcase-line',
    tool: 'ri:tools-line',
    integration: 'ri:plug-line'
  }
  return iconMap[category] || 'ri:apps-line'
}

/** 分类图标背景渐变映射 */
export const getIconBg = (category: string): string => {
  const bgMap: Record<string, string> = {
    business: 'linear-gradient(135deg, var(--el-color-primary), var(--el-color-primary-light-3))',
    tool: 'linear-gradient(135deg, #667eea, #764ba2)',
    integration: 'linear-gradient(135deg, #4facfe, #00f2fe)'
  }
  return bgMap[category] || 'linear-gradient(135deg, #a8edea, #fed6e3)'
}

/**
 * 格式化价格展示
 * @param price 价格数值（支持小数）
 * @param unit 价格单位（元、积分、金币等）
 * @returns 格式化后的价格字符串，如 "¥999" "999积分"
 */
export const formatPrice = (price: number, unit: string): string => {
  if (!unit || unit === '元') {
    return `¥${price}`
  }
  return `${price}${unit}`
}

/** 购买引导信息 */
export interface BuyGuideInfo {
  message?: string
  price?: number
  buy_url?: string
}

/** 包体积格式化：字节 → 可读大小。不复用 utils/file 的 formatFileSize：它对 0 会算出 NaN，且无「未知」兜底 */
export const formatPackageSize = (bytes: number): string => {
  const size = Number(bytes) || 0
  if (size >= 1048576) return `${(size / 1048576).toFixed(2)} MB`
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KB`
  return size > 0 ? `${size} B` : '未知'
}

/**
 * 升级确认弹窗的共享状态：由 StoreUpgradeDialog 组件挂载渲染，
 * store/index.vue 与 store/detail.vue 共用同一份单例状态。
 */
export interface UpgradeDialogState {
  visible: boolean
  preflight: StoreUpdatePreflight | null
  /** 级联升级：存在需临时停用的依赖方 */
  cascade: boolean
  /** 阻断模式：基座不兼容，只展示提示不给升级出口 */
  blockMode: boolean
}

export const upgradeDialogState = reactive<UpgradeDialogState>({
  visible: false,
  preflight: null,
  cascade: false,
  blockMode: false
})

let upgradeDialogResolver: ((confirmed: boolean) => void) | null = null

/** 打开升级弹窗，返回 Promise：确定升级 resolve(true)，取消/关闭 resolve(false) */
function openUpgradeDialog(
  preflight: StoreUpdatePreflight,
  cascade: boolean,
  blockMode: boolean
): Promise<boolean> {
  upgradeDialogState.preflight = preflight
  upgradeDialogState.cascade = cascade
  upgradeDialogState.blockMode = blockMode
  upgradeDialogState.visible = true
  return new Promise((resolve) => {
    upgradeDialogResolver = resolve
  })
}

/** 供弹窗组件在按钮/关闭动作时回传结果 */
export function resolveUpgradeDialog(confirmed: boolean): void {
  upgradeDialogState.visible = false
  upgradeDialogResolver?.(confirmed)
  upgradeDialogResolver = null
}

/**
 * 升级预检 + 确认弹窗：返回后续应执行的动作。
 * 弹窗按「当前版本 → 升级版本 → 包大小 → 更新内容 → 兼容性 → 不可用提示」排版；
 * 不兼容时硬性阻断（无确认按钮）。
 * - purchase：付费未购买，交由视图展示各自的购买弹窗
 * - update(cascade)：可升级，cascade 决定走级联还是普通更新
 * - none：已最新 / 不兼容 / 不可级联（均已弹提示），视图无需升级
 * 用户在确认中点「取消」时，本函数向上抛 reject，由调用方 catch 静默中止。
 */
export async function confirmStoreUpgrade(
  id: string
): Promise<
  | { action: 'purchase'; buy: BuyGuideInfo }
  | { action: 'update'; cascade: boolean }
  | { action: 'none' }
> {
  const pf = (await storeApi.preflight(id)) as StoreUpdatePreflight

  if (pf.need_purchase) {
    return {
      action: 'purchase',
      buy: { message: pf.block_reason, price: pf.price, buy_url: pf.buy_url }
    }
  }
  if (!pf.has_update) {
    ElMessage.success('当前已是最新版本')
    return { action: 'none' }
  }

  // 不兼容：阻断弹窗，优先级最高，不给继续升级的出口
  if (pf.compatible === false) {
    await openUpgradeDialog(pf, false, true)
    return { action: 'none' }
  }

  if (!pf.can_cascade) {
    await ElMessageBox.alert(pf.block_reason || '无法自动升级，请人工处理', '无法升级', {
      type: 'warning',
      confirmButtonText: '我知道了'
    })
    return { action: 'none' }
  }

  const cascade = !!pf.need_cascade
  const confirmed = await openUpgradeDialog(pf, cascade, false)
  if (!confirmed) {
    // 与旧 ElMessageBox.confirm 的 cancel reject 行为保持一致，调用方统一 catch
    throw new Error('cancel')
  }
  return { action: 'update', cascade }
}

/**
 * 呈现级联升级结果（成功→重载提示；失败已回滚→错误；其他→提示）。
 * 返回 needPurchase 时视图应改走购买引导（级联中途市场侧状态变化的兜底）。
 */
export async function presentCascadeResult(
  res: StoreCascadeResult
): Promise<{ needPurchase?: BuyGuideInfo }> {
  if (res?.need_purchase) {
    return { needPurchase: { message: res.message, price: res.price, buy_url: res.buy_url } }
  }
  if (res?.ok) {
    const affected = (res.affected_enable || []).length
    let msg = `升级成功${affected ? `，已自动恢复 ${affected} 个依赖插件` : ''}。`
    if (res.need_reload) {
      msg += `\n${res.reload_message || '请重启服务使变更完全生效。'}`
    }
    msg += '\n\n建议立即刷新页面以加载最新界面。'
    await ElMessageBox.confirm(msg, '升级成功', {
      type: 'success',
      confirmButtonText: '立即刷新',
      cancelButtonText: '稍后刷新'
    })
      .then(() => location.reload())
      .catch(() => {})
    return {}
  }
  if (res?.reverted) {
    const notes: string[] = []
    if (res.file_rolled_back === false) notes.push('目标插件文件未能自动回滚')
    if (res.db_rolled_back === false && res.db_note) notes.push(res.db_note)
    if (Array.isArray(res.enable_failed) && res.enable_failed.length) {
      notes.push(
        `以下插件未能自动恢复启用，请前往管理应用手工启用：${res.enable_failed.join('、')}`
      )
    }
    await ElMessageBox.alert(
      `${res.message || '升级失败，已回滚至升级前状态'}${notes.length ? '\n\n' + notes.join('\n') : ''}\n\n详细原因请查看后端日志。`,
      '升级失败（已回滚）',
      { type: 'error', confirmButtonText: '我知道了' }
    )
    return {}
  }
  await ElMessageBox.alert(res?.message || '升级未完成', '提示', {
    type: 'warning',
    confirmButtonText: '我知道了'
  })
  return {}
}
