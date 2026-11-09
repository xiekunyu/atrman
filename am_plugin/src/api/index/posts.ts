import pluginRequest from '@/utils/http/plugin'

/**
 * 前台公开接口示例（前台 index 板块专用）
 *
 * 约定：src/api/index/** 只允许调用后端 $noNeedLogin 白名单里的公开接口，
 * 保证「直连 /app/{name} 无登录、无 Bridge」时前台页面可用。
 * URL 由 pluginRequest 自动拼接 /app/{VITE_PLUGIN_NAME} 前缀。
 */
export interface IndexPost {
  id: number
  title: string
  summary: string
  content: string
  created_at: string
}

export default {
  /** GET /app/{name}/public/posts （CMS 首页文章列表原型） */
  posts() {
    return pluginRequest.get<{ list: IndexPost[]; total: number }>({
      url: '/public/posts'
    })
  },

  /** GET /app/{name}/public/detail?id=x （内容详情页原型） */
  detail(id: number | string) {
    return pluginRequest.get<IndexPost>({
      url: '/public/detail',
      params: { id }
    })
  }
}
