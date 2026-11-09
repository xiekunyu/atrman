import { ref } from 'vue'

/**
 * 版本升级日志（与基座 am-web 契约保持一致：ref<UpgradeLog[]>）
 * upgrade.ts 通过 upgradeLogList.value 读取，字段需为 requireReLogin。
 */
interface UpgradeLog {
  version: string // 版本号
  title: string // 更新标题
  date: string // 更新日期
  detail?: string[] // 更新内容
  requireReLogin?: boolean // 是否需要重新登录
  remark?: string // 备注
}

export const upgradeLogList = ref<UpgradeLog[]>([
  {
    version: '1.0.0',
    title: '初始版本',
    date: '2025-01-01',
    detail: [
      '插件脚手架初始化',
      '集成 Art Design Pro 核心框架',
      '支持 qiankun 微前端加载',
      '自动继承基座登录态'
    ],
    requireReLogin: false
  }
])
