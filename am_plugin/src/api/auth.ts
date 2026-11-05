import request from '@/utils/http'

/** 获取用户信息 */
export function fetchGetUserInfo() {
  return request.post<Api.Auth.UserInfo>({
    url: '/pub/system/user'
  })
}
