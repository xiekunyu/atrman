/**
 * API 接口类型定义模块
 *
 * 提供所有后端接口的类型定义
 *
 * ## 主要功能
 *
 * - 通用类型（分页参数、响应结构等）
 * - 认证类型（登录、用户信息等）
 * - 系统管理类型（用户、角色等）
 * - 全局命名空间声明
 *
 * ## 使用场景
 *
 * - API 请求参数类型约束
 * - API 响应数据类型定义
 * - 接口文档类型同步
 *
 * ## 注意事项
 *
 * - 在 .vue 文件使用需要在 eslint.config.mjs 中配置 globals: { Api: 'readonly' }
 * - 使用全局命名空间，无需导入即可使用
 *
 * ## 使用方式
 *
 * ```typescript
 * const params: Api.Auth.LoginParams = { userName: 'admin', password: '123456' }
 * const response: Api.Auth.UserInfo = await fetchUserInfo()
 * ```
 *
 * @module types/api/api
 * @author Art Design Pro Team
 */

declare namespace Api {
  /** 通用类型 */
  namespace Common {
    /** 分页参数 */
    interface PaginationParams {
      /** 当前页码 */
      current: number
      /** 每页条数 */
      size: number
      /** 总条数 */
      total: number
    }

    type ApiData = {
      [key: string]: any
    }

    /** 通用搜索参数 */
    type CommonSearchParams = Pick<PaginationParams, 'current' | 'size'>

    /** 分页响应基础结构 */
    interface PaginatedResponse<T = any> {
      data: T[]
      page: number
      size: number
      count: number
      code: number
    }

    /** 启用状态 */
    type EnableStatus = '1' | '2'
  }

  /** 认证类型 */
  namespace Auth {
    /** 登录参数 */
    interface LoginParams {
      username: string
      password: string,
      code: string,
      uuid: string,
    }

    /** 登录响应 */
    interface LoginResponse {
      accessToken: string
      refreshToken: string
      user: UserInfo
    }

    /** 用户信息 */
    interface UserInfo {
      id: number
      // 统一主体全局唯一ID（人），跨模块引用一律用 sid，不用 id
      sid?: string
      username: string
      nickname: string
      avatar: string
      gender: number
      motto: string
      email: string
      mobile: string
      // 身份类别：1=管理员,2=成员（对应后端 account_type）
      account_type?: number
      // 便捷标记（派生自 account_type，权威判定以查库为准）
      is_admin?: boolean
      avatar_id?: number
      last_login_time?: number | string
      last_login_ip?: string
      ip_location?: string
      buttons?: string[]
      roles?: string[]
    }

    /** 验证码 */
    interface Captcha {
      image: string
      uuid: string
      result: number
    }
  }

  namespace SysUser {
    /** 用户列表参数 */
    interface UserListParams extends Api.Common.CommonSearchParams {
      username?: string
      nickname?: string
      status?: string
    }
  }

  /** 系统管理类型 */
  namespace SystemManage {

        /** 字典数据 */
    interface DictData {
      [key: string]: DictItem[]
    }

    /** 字典列表项 */
    interface DictItem {
      id: number
      name: string
      value: string
      sort: number
      color: string
       [key: string]:any
    }

    /** 网站配置 */
    interface WebsiteInfo {
      name?: string
      description?: string
      logo?: string | number | Array<string | number>
      logo_displayData?: Api.Common.ApiData[]
      favicon?: string
      copyright?: string
      [key: string]: any
    }

    /** 系统信息 */
    interface SystemInfo {
      website?: WebsiteInfo
      [key: string]: any
    }

    /** 用户列表 */
    type UserList = Api.Common.PaginatedResponse<UserListItem>

    /** 用户列表项 */
    interface UserListItem {
      id: number
      avatar: string
      status: string
      username: string
      gender: string
      nickname: string
      mobile: string
      email: string
      motto: string
      createBy: string
      createTime: string
      updateBy: string
      updateTime: string,
    }

    /** 用户搜索参数 */
    type UserSearchParams = Partial<
      Pick<UserListItem, 'id' | 'username' | 'gender' | 'mobile' | 'email' | 'status'> &
        Api.Common.CommonSearchParams
    >

    /** 角色列表 */
    type RoleList = Api.Common.PaginatedResponse<RoleListItem>

    /** 角色列表项 */
    interface RoleListItem {
      id: number
      name: string
      code: string
      level: number
      remark: string
      status: number
      sort: number
    }

    /** 角色搜索参数 */
    type RoleSearchParams = Partial<
      Pick<RoleListItem, 'id' | 'name' | 'code' | 'remark' | 'status' | 'level'> &
          Api.Common.CommonSearchParams
      >

    type RemoteList = Api.Common.PaginatedResponse<RemoteItem>

    interface RemoteItem {
      id: number; // ID
      name: string; // 显示名称
      code: string; // 唯一标识
      api_url: string; // 接口地址
      method: number; // 1: get, 2: post
      type: number; // 1: 列表, 2: 树形
      label_field: string; // 展示字段
      value_field: string; // 值字段
      children_field?: string; // 树形子节点字段
      params?: Record<string, any>; // 请求参数
      header?: Record<string, any>; // 请求头
      [key: string]: any;
    }


    // 标准选项结构
    interface BaseOption {
      label: string;
      value: string | number;
      children?: BaseOption[];
      [key: string]: any;
    }
  }

    

}
