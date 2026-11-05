import request from '@/utils/http'

export interface OAuthTokenResponse {
  access_token: string
  token_type: string
  expires_in: number
  scope: string
}

export interface OAuthUserInfo {
  id: number
  username: string
  nickname?: string
  avatar?: string
  email?: string
  mobile?: string
  dept_id?: number
  dept_name?: string
}

export interface SSOResponse {
  url: string
}

export default {
  /** 生成 SSO 跳转链接 */
  ssoUrl(id: number | string) {
    return request.post<Api.Common.ApiData>({
      url: '/oauth/sso/url',
      data: { id }
    })
  },

  /** 验证 SSO 凭证（三方应用调用） */
  ssoVerify(ssoToken: string) {
    return request.get<Api.Common.ApiData>({
      url: '/oauth/sso/verify',
      params: { sso_token: ssoToken }
    })
  }
}
