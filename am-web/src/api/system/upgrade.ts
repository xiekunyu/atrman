import request from '@/utils/http'

/**
 * 主程序升级 API
 *
 * 约定：接口只返回可展示的元信息（版本号/校验值/大小/差异清单），
 * 包体真实路径与直链一律不出现，下载在服务端按一次性 ticket 走。
 */
export default {
  /** 生成升级计划（增量链选包 + 依赖/迁移/差异文件概览，不改动任何文件） */
  plan() {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/store/system-update/plan',
      // 「已是最新版本」「链不完整」等属正常业务态，由页面内联告知，不走全局错误弹窗
      showErrorMessage: false
    })
  },

  /** 投递升级任务（strategy: merged 合并执行 / sequential 逐级执行；仅超级管理员） */
  execute(strategy: 'merged' | 'sequential') {
    return request.post<Api.Common.ApiData>({
      url: '/plugin/store/system-update/execute',
      data: { strategy }
    })
  },

  /** 查询任务快照与历史日志（供进度页轮询） */
  status(jobId: string) {
    return request.get<Api.Common.ApiData>({
      url: '/plugin/store/system-update/status',
      params: { job_id: jobId },
      // 轮询接口：快照过期后后端会返回业务失败，1.2s 一次不能反复弹错误提示
      showErrorMessage: false
    })
  }
}
