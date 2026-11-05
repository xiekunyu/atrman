import request from '@/utils/http'

/** 登录 */
export function fetchLogin(params: Api.Auth.LoginParams) {
  return request.post<Api.Auth.LoginResponse>({
    url: '/pub/auth/login',
    params
  })
}

/** 获取用户信息 */
export function fetchGetUserInfo() {
  return request.post<Api.Auth.UserInfo>({
    url: '/pub/system/user'
  })
}

/** 获取验证码 */
export function fetchCaptcha() {
  return request.post<Api.Auth.Captcha>({
    url: '/pub/auth/captcha'
  })
}
