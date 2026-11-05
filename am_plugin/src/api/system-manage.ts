import request from '@/utils/http'
import { AppRouteRecord } from '@/types/router'

/** 按插件标识获取该插件自己的菜单树（后端驱动侧栏与路由） */
export function fetchGetPluginMenuList(appName: string) {
  return request.get<AppRouteRecord[]>({
    url: '/sys/menu/pluginMenu',
    params: { app_name: appName }
  })
}

/** 获取字典列表（公开接口） */
export function fetchGetDictList() {
  return request.get<Api.SystemManage.DictData>({
    url: '/pub/system/dict'
  })
}

/** 获取系统信息（公开接口） */
export function fetchGetSystemInfo() {
  return request.get<Api.SystemManage.SystemInfo>({
    url: '/pub/system/systemInfo'
  })
}

/** 获取子部门以及成员（公开接口） */
export function fetchGetDepartSubList(params: Record<string, any>) {
  return request.get<Api.Common.ApiData>({
    url: '/pub/system/departSub',
    params
  })
}
