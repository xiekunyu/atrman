---
name: am_plugin_dev
description: 在 Atrman（artman）基座上开发插件。当用户要新建插件、写插件后端（控制器/模型/服务/事件/服务接管/队列）、插件前端（Vue3 微应用 / 纯 HTML / 小部件）、插件安装升级与打包，或排查插件装不上、白屏、404、403、注册不生效等问题时使用。
---

# Atrman 插件开发

Atrman 是技术栈中立的后台基座，业务全部以**插件**增量交付。本 skill 只讲插件特有的契约、生命周期与装配排错；**通用业务编码规范与基座一致，遵循 [[am_dev_standard]]**（真源是仓库根目录《开发规范.md》），不再重复。权威细节以 `am_doc/docs/plugin/` 文档和基座代码为准。

## 通用规范直接照用（来自 am_dev_standard）

下列规则插件业务代码**同样遵守**，写法完全一致：

- 控制器继承 `app\basic\BaseController`，`initialize()` 初始化 model，validate 按需初始化；CRUD 方法名约定（`index/read/save/update/delete/batchDelete/changeStatus`，前端列表方法叫 `list`）、`buildMap → search → getList → returnListData` 列表套路；
- Model 继承 `BaseModel`、`$name` 写完整表名、搜索器按需才加、Service 满足复用三条件才抽；
- `create_time/update_time/delete_time` 用 INT 时间戳且禁止手填，业务 `_time` 字段手动 `time()`；
- 响应体 `success()/warning()/error()`、键名 **`msg`**；错误文案口径、事务 `$this->transaction()`、删除前关联检查、内置数据禁删；
- 建表必备字段与索引命名（`uk_`/`idx_`）、不用物理外键；
- 前端 API 层（`params`/`data`、JSDoc、`Api.Common.ApiData`）、标准列表页 `useTable + tableOperation`、搜索/弹窗拆 `modules/`、`v-auth`、`art-*` 布局类；
- 命名、缩进、引号、安全（ORM 绑定、密码 `passwordHash()`、双重权限校验）与性能（防 N+1、分页、防抖）要求。

### 同一套规范，插件里的差异点

| 维度 | 基座 | 插件 |
| --- | --- | --- |
| 命名空间 | `app\...` | `plugin\{name}\app\...` |
| 路由前缀 | `#[RouteGroup('/sys/{module}')]` | `#[RouteGroup('/app/{name}')]`，页面入口另在 `config/route.php` 手注 |
| 权限 slug | `system:{module}:{action}` | `{name}:{module}:{action}`（如 `am_store:app:index`），冒号规则与 `v-auth` 不变 |
| 数据表 | `system_{module}` | `{name}_{table}`，建表写在 `install.sql` 并用 `__PREFIX__` 占位 |
| 缓存/队列等注册 | 直接注册 | 必须在 `Plugin::boot()` 注册且带 `owner`（见下）；菜单/权限/配置走生命周期钩子声明 |
| 前端工程 | `am-web/` | 独立工作区 `am_frontends/{name}/`，页面分 `views/index`（公开）与 `views/manage`（后台）双入口；API/页面组件规范照用 |

## 真源与参考实现

- 官方文档：`am_doc/docs/plugin/`（概念 5 页、后端 11 页、前端 7 页、cookbook）
- 后端骨架模板：`am-serve/stub/plugin/*.stub`
- 前端框架真源：`am_plugin/`（Vue3 + TS + Vite + Element Plus + qiankun，**不直接构建**）
- 前端装配脚本：`am_frontends/create-plugin.mjs`
- 真实插件范例：
  - `am-serve/plugin/am_demo/` —— 官方教学示例（最小完整插件，先读它）
  - `am-serve/plugin/am_member/`、`am_store/`、`am_aibase/` —— 复杂能力参考（会员鉴权、应用市场、AI 基座）

## 标准开发流程（顺序不能反）

1. **基座后台建插件**：插件管理 →「创建自定义插件」，填插件名。基座生成 `am-serve/plugin/{name}/`（manifest、Plugin.php、config、双入口控制器、占位视图）。需要会员登录就勾选「启用会员登录能力」（要求 am_member 已启用）。
2. **装配前端工作区**：
   ```bash
   cd am_frontends
   node create-plugin.mjs am_xxxxx
   ```
   从 `am_plugin` 拷贝到独立工作区 `am_frontends/{name}/`，脚本**不生成任何 PHP**。重装配先删工作区目录。
3. **开发**：`cd am_frontends/{name} && pnpm install && pnpm dev`（默认 https://localhost:3007）。
4. **构建落位**：`pnpm build`（= vite build → vue-tsc --noEmit → post-build.ts）。产物按 `.env` 的 `VITE_PLUGIN_ROOT` 回写插件目录：JS/CSS 进 `public/assets/`，双入口视图覆盖 `app/view/app/index.html` 与 `manage.html`。
5. 后台启用插件，验证两个入口。

环境：Node ≥ 20.19、pnpm ≥ 8.8（只支持 pnpm）、后端默认 `http://localhost:8787`。**`pnpm-lock.yaml` 必须保留**，否则小版本漂移导致 vue-tsc 失败。

## 命名铁律

- 插件名匹配 `/^am_[a-z][a-z0-9_]*$/`，`am_` 后**至少 5 个字符**（`am_demo` 是历史短名，不要参照）。
- 目录名 == `manifest.name` == PHP 命名空间末段（`plugin\{name}\...`），三者不一致是最常见装配失败。
- 避开保留名：`am_system`/`am_app`/`am_store`/`am_member` 等（完整清单见 `PluginManifestService::RESERVED_NAMES`，创建前基座会校验）。
- 业务表名以 `{name}_` 前缀；拼名用 `BasePlugin::table()`。

## 后端关键约定

### manifest.json（唯一元数据来源）

- `support_version`：要求的最低基座版本；`entry`/`manage_entry` 只有键不存在时才补默认值，显式 `""` 表示无此入口（两者皆空 = 纯服务插件，无页面）。
- `is_dev:1`：开发模式，清单可重生成；发布包置为生产值。
- `composer.require` 声明自有 Composer 依赖，安装时合并进根 vendor（受 `config/plugin_extend.php` 的 `composer_enabled` 控制）。

### Plugin.php 生命周期（继承 `app\basic\BasePlugin`）

- **进程级静态注册（事件、服务接管、适配器、中间件、任务、身份 provider、通道鉴权）一律写 `boot()`**，且每个 worker 都会执行——注册逻辑必须幂等。
- 每个注册都传 `owner: '{插件名}'`，卸载时基座按 `forgetByOwner` 反向清理。
- **数据声明（菜单、权限、配置组）写 `afterInstall` 和 `afterEnable` 两个钩子，调同一套方法**——自定义插件创建时不跑 install.sql，启用钩子是开发环境主要注册时机；`afterUpgrade` 也要跑权限同步。
- `init` 只在 `reload()` 时触发，安装/启用/升级都不调它。
- 完成注解/权限变更后调 `ReflectionCache::clear()`（尤其 `afterUpgrade`），否则新权限 slug 不生效、非超管 403。
- 参考实现直接看 `am-serve/plugin/am_demo/Plugin.php`。

### 控制器与路由

- 业务接口用注解：类级 `#[RouteGroup('/app/{name}')]` + 方法级 `#[Get]`/`#[Post]`，**不要**在方法路径里重复前缀，也不要在 route.php 重复注册。
- 页面入口 `/app/{name}`、`/app/{name}/manage` 注解生成不出来，必须在 `config/route.php` 手动 `Route::get`。
- 公开 action 放入 `$noNeedLogin`；后台接口不写白名单，由 AuthCheck + `#[Permission]` 注解拦截。
- 按钮权限在控制器方法标 `#[Permission(title, slug)]`，安装/启用时 `syncPermissionsFromControllers` 自动扫描落库，不要手写权限 INSERT。
- **响应体用 helper**：`success()`/`warning()`/`error()`，键名是 **`msg` 不是 `message`**（前端拦截器只读 msg）。`am_demo` 的 `info` 手写 `message` 是反面教材，别照抄。
- BaseController 提供 `$this->userInfo`、`validate()`、`getList()`、`search()`、`transaction()`、`assertCanManage()` 等。

### 数据与扩展边界

- `install.sql` 只放**插件自有业务表 + 种子数据**：建表 `CREATE TABLE IF NOT EXISTS`，种子按自然键幂等 upsert；表前缀用 `__PREFIX__`（另有 `{{APP_ID}}`/`{{APP_NAME}}`/`{{APP_ENTRY_PATH}}` 占位符）。
- **不要**把菜单/配置/权限/小部件 INSERT 写进 SQL，一律程序声明式写入（`PluginRegistrar::menu()`/`configSchema()` 等）。
- **不要**手写 `app:entry:` 前缀的顶级入口菜单，基座 `PluginManager::syncEntry` 独占写入；子菜单 `parent_code` 用 `PluginManager::entryCode($name)`。
- 需要备份的表在 `Plugin::backupTables()` 声明。
- 特性开关在 `config/plugin_extend.php`：`event/service/task/queue/push/composer` 默认开，**`middleware` 默认关**——中间件不生效先查开关。改开关需重启 worker。
- 服务接管 `ServiceRegistry::bind/extend` 要 `priority > 0` 才能盖过基座默认实现（默认优先级 0）。
- `queue dispatch`、push `toChannel` 等“尽力而为”API 失败返回 `false` 不抛异常，调用方按返回值降级。

## 前端关键约定

- **目录即契约**：`src/views/index/**` = 公开前台（无 Bridge、可能无登录态）；`views/manage/**` = 后台（菜单驱动、需登录）。`api/index/**` 只能调 `$noNeedLogin` 白名单接口。
- 前台禁止 import 菜单 store、worktab、路由守卫等后台模块；共享只允许 `sdk/*`、`utils/*`、通用组件。前台依赖登录的功能用 `<RequireLogin>`/`requireLogin()` 条件渲染 + 降级，**禁止自建登录页**（登录统一跳 am_member，入口由 `VITE_MEMBER_ENTRY` 配置）。
- qiankun UMD 单 bundle：`build.lib.name` 必须 `=== manifest.name`，全局名不一致会直接白屏。
- 管理页菜单 `component` 写相对 `src/views/` 的**字符串路径**（ComponentLoader 解析），不是 `import()`；前台页在 `router/modules/index.ts` 用静态 `import()`。
- 调基座能力前先 `hasBridge()` 判空。
- 独立运行默认入口是 `front`：`/#/index/home` 看前台，`/#/manage/home` 看后台。基座内联调把 manifest 入口临时指向 dev URL 且 `is_dev:1`。

## 改了不生效？先查缓存

- 改注解、`$noNeedLogin`、路由 → Webman 反射/路由缓存（`app/cache/ReflectionCache.php`），清缓存或重启 worker。
- 改 `config/plugin_extend.php` 开关 → 重启。
- 新增 `#[Permission]` 后 → `ReflectionCache::clear()` + 确认 `afterUpgrade/afterEnable` 跑了权限同步。
- 前端改动 → 确认 `pnpm build` 产物已回写插件目录，别在 `am_plugin` 根目录构建（那里只是真源模板）。

## 最小验证路径

装 → 启用 → 打开管理入口（免登注入 + 菜单落地）→ 直访 `/app/{name}`（无登录态渲染）→ 触发用到的扩展通道 → 禁用/卸载验证 `forgetByOwner` 清理对称。

发布前完整自检过一遍 `am_doc/docs/plugin/cookbook/checklist.md`（命名、路由、注册 owner、SQL 边界、缓存、鉴权口径）。

## 深入阅读（按需）

- 概念：`am_doc/docs/plugin/concept/`（manifest、结构、生命周期、双入口、安装升级）
- 后端：`am_doc/docs/plugin/backend/`（registrar、event、service-registry、storage-ext、sms-notify-ext、queue-task-push、identity-ext、middleware、member-auth）
- 前端：`am_doc/docs/plugin/frontend/`（overview、bridge、sdk、crud、html、sso、widget）
- Cookbook：`am_doc/docs/plugin/cookbook/`（minimal-html、am_demo_walkthrough、checklist）
