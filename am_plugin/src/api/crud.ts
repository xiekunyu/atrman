import request from '@/utils/http'

interface CrudRequester {
  get<T = any>(config: any): Promise<T>
  post<T = any>(config: any): Promise<T>
}

interface CrudOptions {
  /** 附加到 list 请求的额外配置（如 decompress） */
  list?: Record<string, any>
  /** 请求器，默认 request；插件端可传 pluginRequest 请求插件自己的后端 */
  requester?: CrudRequester
}

/**
 * 生成标准 CRUD API
 * @param base 接口路径前缀，如 /sys/role；插件自己的后端用 pluginRequest 时传业务路径如 /order
 */
export function createCrudApi(base: string, options: CrudOptions = {}) {
  const { list: listOptions, requester = request } = options
  return {
    list(params: Record<string, any>) {
      return requester.get<Api.Common.ApiData>({
        url: `${base}/index`,
        params,
        ...listOptions
      })
    },
    read(id: number) {
      return requester.get<Api.Common.ApiData>({
        url: `${base}/read?id=${id}`
      })
    },
    save(data: Record<string, any>) {
      return requester.post<Api.Common.ApiData>({
        url: `${base}/save`,
        data
      })
    },
    update(data: Record<string, any>) {
      return requester.post<Api.Common.ApiData>({
        url: `${base}/update`,
        data
      })
    },
    delete(params: Record<string, any>) {
      return requester.post<Api.Common.ApiData>({
        url: `${base}/delete`,
        data: params
      })
    },
    batchDelete(params: Record<string, any>) {
      return requester.post<Api.Common.ApiData>({
        url: `${base}/batchDelete`,
        data: params
      })
    }
  }
}
