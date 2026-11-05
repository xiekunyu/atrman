import { useUserStore } from '@/store/modules/user'

export const authBridgeService = {
  getLoginState() {
    const userStore = useUserStore()
    const user = userStore.getUserInfo || {}

    return {
      isLogin: userStore.isLogin,
      // 统一主体标识：跨模块引用请优先用 sid；userId(自增) 仅为向后兼容保留
      sid: user.sid,
      userId: user.id,
      username: user.username,
      nickname: user.nickname,
      avatar: user.avatar,
      accountType: user.account_type,
      isAdmin: user.is_admin
    }
  },

  getUserProfile() {
    return useUserStore().getUserInfo
  },

  getSession() {
    const userStore = useUserStore()

    return {
      accessToken: userStore.accessToken,
      refreshToken: userStore.refreshToken,
      user: userStore.getUserInfo
    }
  },

  getAccessToken() {
    return useUserStore().accessToken
  },

  logout() {
    useUserStore().logOut()
    return true
  }
}
