import request from '@/utils/http'

export default {
  /** 获取系统信息（主程序版本、运行环境、核心组件、数据库/Redis、应用统计与更新状态） */
  index() {
    return request.get<Api.Common.ApiData>({
      url: '/sys/systemInfo/index'
    })
  }
}
