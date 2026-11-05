import request from '@/utils/http'
import { AppRouteRecord } from '@/types/router'

/** 获取菜单列表（公开接口） */
export function fetchGetMenuList() {
  return request.get<AppRouteRecord[]>({
    url: '/pub/system/menu'
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
