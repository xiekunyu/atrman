---
name: am_dev_standard
description: Atrman 统一开发规范（am-serve 后端 / am-web 前端，插件业务代码同样遵循）。当用户要新增模块、写控制器/模型/验证器/服务/缓存类/队列任务、建数据库表、写前端 API 与表格页面，或做代码审查时使用。插件特有的契约、生命周期与装配排错用 am_plugin_dev。
---

# Atrman 基座开发规范

本 skill 是根目录《开发规范.md》（v1.0，2026-10-05）的执行版速查。完整代码模板以源文档为准：`开发规范.md`（仓库根目录）。

适用范围：**基座核心** `am-serve`（Webman/PHP ≥8.2 + ThinkORM + MySQL + Redis + JWT）与 `am-web`（Vue3 + TS + Vite + Element Plus + Pinia）。**插件（[[am_plugin_dev]]）的业务代码同样按本规范写**——分层、CRUD 约定、时间字段、响应体、建表、前端 API/页面套路全部适用，差异只有命名空间、路由前缀、权限 slug 首段、表名前缀和注册方式，见 am_plugin_dev 的差异对照表。

## 新增一个系统模块的固定顺序

建表 → Model（`app/model/system/`）→ Validate（`app/validate/system/`，按需）→ Controller（`app/controller/system/`）→ 前端 API（`am-web/src/api/system/`）→ 页面（`am-web/src/views/system/{module}/`）→ 配菜单权限（`#[Permission]` 注解 + 后台菜单）。

## 后端铁律

### 控制器

- 继承 `app\basic\BaseController`，在 `initialize()` 里初始化 `$this->model`；validate **按需初始化**（真用到才 new）。
- 类级 `#[RouteGroup('/sys/{module}')]`；登录/公开接口/第三方回调可不继承 BaseController、不加 Permission。
- CRUD 方法名约定（推荐但不强制，**只写实际需要的方法，不凑空方法**）：

  | 功能 | 后端方法 | HTTP | 前端 API 方法 |
  | --- | --- | --- | --- |
  | 列表 | `index` | GET | `list`（唯一前后端不一致） |
  | 详情 | `read` | GET | `read` |
  | 新增 | `save` | POST | `save` |
  | 更新 | `update` | POST | `update` |
  | 删除 | `delete` | POST | `delete` |
  | 批量删除 | `batchDelete` | POST | `batchDelete` |
  | 状态切换 | `changeStatus` | POST | `changeStatus` |

- 列表套路：`$request->buildMap([['field',''],...])` → `$this->search()` → `$this->getList()` → `$this->returnListData()`。
- 必填参数先 `if (empty($id)) return warning('参数错误');`；查不到用 `findOrEmpty` + `warning('数据不存在')`。
- 删除前检查子记录/关联数据；内置数据（`is_sys=1`）禁止删除。
- 多表写操作必须包 `$this->transaction(function() { ... })`。
- 树形：`\app\utils\Tree::createListToTree($list, 'id', 'pid', 'children')`。

### 模型 / 验证器

- Model 继承 `BaseModel`，`protected $name` 写**完整表名**；主键默认 id 不声明 `$pk`。
- 搜索器**需要关键词搜索才加**，方法名驼峰 `searchKeywordsAttr`（时间范围搜索器基类已有）；简单字典表不加。
- 关联：中间表模型继承 ThinkORM `Pivot`。
- 数据查询、搜索器、关联、数据组装（`getStructure()`）、权限静态方法放 Model；纯工具放 `app/utils/`；业务相关无 Model 的 helper 放 `app/functions.php`。
- Validate 继承 `BaseValidate`，定义 `$rule`/`$message`/`$scene`（save/update 分场景）。

### 时间字段（高频坑）

- `create_time` / `update_time` / `delete_time` 用 **INT UNSIGNED 存 Unix 时间戳，禁止 DATETIME**，且**禁止手动赋值**——ThinkORM 已配 `auto_timestamp` 自动维护。
- 其他 `_time` 业务字段（`last_login_time`、`publish_time`、`expire_time`…）需手动 `time()` 赋值。
- 查询输出时 ORM 自动把 INT 时间戳格式化成字符串，直接返回前端即可。

### Service / Interface / Queue

- Service 要**同时满足**：2+ 处复用、逻辑独立、可能跨模块才抽；简单 CRUD 留在控制器，禁止过度设计。命名 `{Name}Service.php`，统一返回 `['success'=>bool,'message'=>string,'data'=>mixed]`。
- **静态状态红线**：Service 可以用 static 门面，但方法必须无状态——禁止静态属性/函数内 `static $var` 存请求、用户、token 态（请求态走 `$request->动态属性`、控制器实例属性、`Webman\Context`）；进程内缓存统一用 `app\cache\TtlMemo`（TTL+cap+flush，禁止裸静态数组）；Registry 只在 `boot()` 写、请求期只读（`RegistryGuard` debug 拦截）。
- 扩展点契约放 `app/interface/{Name}Interface.php`，完整 PHPDoc。
- 队列消费者放 `app/queue/{Name}Job.php`（implements `Webman\RedisQueue\Consumer`），任务必须**幂等**（可能重试）、catch 全部异常；`Client::send($queue, $data, $delay)` 投递。实时/需同步返回的场景不用队列。

### 缓存

- 缓存类放 `app/cache/{Name}Cache.php`，全部走 `CacheManager`（`remember/clearKey/clearKeys/clearTags`），配置在 `config/cache_manage.php`，带标签便于按用户/角色批量清。
- 数据改完必须清缓存：用户变更 → `UserInfoCache::clearUserAll()`；角色变更 → 三个缓存 + 按角色清；菜单变更 → `UserMenuCache/UserAuthCache/UserInfoCache` 全清；字典 → `DictCache::clear()`。
- 读多写少才缓存；更新采用**删除策略**而非重写；不设永久缓存。

## 数据库

- 系统表 `system_{module}`，中间表 `system_{a}_{b}`（模型继承 Pivot），表前缀 `am_` 可配；插件表以插件名前缀（见 am_plugin_dev）。
- 必备字段：`id`、`create_time/update_time/delete_time`（INT UNSIGNED）、`created_by/updated_by`。
- 常用：`status` TINYINT(1) DEFAULT 1、`sort` INT DEFAULT 0、`remark` VARCHAR(255)。
- 索引命名：唯一 `uk_{field}`、普通 `idx_{field}`、联合 `idx_{a}_{b}`。
- **不用物理外键**，关联关系在应用层维护。

## API 与权限

- 响应体固定 `{code, msg, data, total, page}`（分页键是 `page` 不是 current），用 `success()/warning()/error()`，键名 **`msg`**。
- RESTful 路径：`GET /sys/{m}/index|read`，`POST /sys/{m}/save|update|delete|batchDelete|changeStatus`。
- 权限 slug 格式 **`module:action:operation`，统一冒号**，禁止点号混用：`#[Permission(title: '列表', slug: 'system:user:index')]`；前端 `v-auth="'system:user:save'"`，前后端 slug 必须逐字一致。
- 超管：ID=1，绕过所有权限。
- 业务错误返回 `warning()`，异常抛 `ApiException`；错误文案口径：`'参数错误'`、`'数据不存在'`、`'{操作}失败'`、`'存在{关联}，无法删除'`、`'内置{模块}不能删除'`。

## 前端（am-web）

- API 文件 `src/api/system/{module}.ts`，默认导出对象；统一返回类型 `Api.Common.ApiData`；GET 用 `params`、POST 用 `data`；每个方法写 JSDoc。同业务域多实体可用目录分组（`api/notify/index.ts` + `api/notify/sms-provider.ts`）。
- 标准列表页 `views/system/{module}/index.vue`：`<script setup>` + `useTable`（apiFn 传 `api.list`，防抖 500ms）+ `tableOperation()` mixin；搜索/弹窗拆到 `modules/{module}-search.vue`、`modules/{module}-dialog.vue`。
- 布局类名：外层 `art-full-height`，表格卡片 `art-table-card`；按钮权限用 `v-auth`；行操作用 `ArtButtonTable`（type=edit/delete）。
- 只读页、监控统计、特殊交互**不必强套** useTable，手动加载即可。
- HTTP 客户端 `@/utils/http`，组件用框架封装的 `Art*` / `El*` 组件。

## 代码质量与安全

- 缩进 PHP 4 空格、TS/Vue 2 空格；单引号为主（JSON 双引号）；必加分号；行宽 ≤120；方法间空行。
- 类/组件大驼峰，方法/变量小驼峰，常量全大写。
- 安全：ORM 参数绑定不拼 SQL；`v-html` 谨慎；密码走 `passwordHash()`；上传校验类型与大小；**前后端双重权限校验**（前端 v-auth 只是隐藏，后端注解才是闸门）。
- 性能：避免 N+1（`with()` 预加载、`field()` 按需取列）、列表必分页、搜索防抖、图片懒加载、组件按需加载。

## 代码审查时优先检查

1. 有没有手动给 create_time/update_time 赋值、有没有用 DATETIME；
2. 响应体是不是 `msg`、有没有手写 `message`；
3. 权限 slug 是否全冒号且前后端一致，写操作是否都有 `#[Permission]`；
4. 多表写入是否包事务、删除是否查关联、内置数据是否拦删；
5. 改用户/角色/菜单/字典后是否清对应缓存；
6. 简单 CRUD 是否被过度抽成 Service；队列任务是否幂等；
7. 前端 API 方法名是否符合约定（列表 `list`）、物理外键是否存在；
8. 破坏性操作（删除/批量删除/禁用/改密）是否挡了当前账号自操作（`currentUserId()` 比对）、是否经 `assertCanManage($level, $targetId)` 超管硬保护。
