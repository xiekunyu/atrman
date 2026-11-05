# ATRMAN · 企业级快速开发框架

<div align="center">

![Version](https://img.shields.io/badge/version-1.0.4-blue.svg) ![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4-777BB4.svg) ![MySQL](https://img.shields.io/badge/MySQL-%3E%3D8.0-4479A1.svg) ![Redis](https://img.shields.io/badge/Redis-%3E%3D5.0-DC382D.svg) ![Webman](https://img.shields.io/badge/Webman-^2.1-00A0E3.svg) ![Vue](https://img.shields.io/badge/Vue-3.5-4FC08D.svg) ![qiankun](https://img.shields.io/badge/qiankun-^2.10-E8443B.svg) ![Element Plus](https://img.shields.io/badge/Element--Plus-^2.11-409EFF.svg) ![Node.js](https://img.shields.io/badge/Node.js-%3E%3D20.19.0-339933.svg) ![License](https://img.shields.io/badge/license-MIT-green.svg)

**一个主打插件扩展的企业级快速开发框架**

把常用的系统能力做成稳定内核，业务功能交给插件扩展。

[🛠 官网](https://atrman.com) · [🚀 在线演示](https://demo.atrman.com) · [🧩 插件应用中心](https://apps.atrman.com) · [📖 文档](#九文档地图) · [🐙 Gitee](https://gitee.com/raingad/atrman) · [💬 交流群](#十一官网与社区)

[核心理念](#一核心理念) · [整体架构](#二整体架构) · [基座能力](#三基座能力) · [插件扩展体系](#四插件扩展体系) · [技术栈](#五技术栈) · [目录结构](#六目录结构) · [快速开始](#七快速开始) · [工具脚本](#八仓库工具脚本) · [文档地图](#九文档地图)

</div>

---

## 一、核心理念

**背景**
市面上大多数后台框架，功能扩展无外乎两条路：改框架源码，或以插件形式接入。

但即便做成插件，本质仍是侵入式的“伪解耦”——插件被牢牢绑定在框架的技术栈、目录结构和生命周期里。后果有三：

- 框架每次迭代都要为插件兼容买单，维护成本随插件数量线性增长；
- 前端技术选型被框架锁死，想换栈就得改框架；
- 插件装得越多，核心越脆弱，出问题时几乎没有可靠的回退路径。

问题的根子不在“有没有插件”，而在插件与基座之间缺少一个中立的契约。没有契约，插件就只能长在框架的肉里。

**Atrman 释义**
>
> - **A**dvanced · 先进：现代化企业级架构，适配复杂业务场景；
> - **T**urbo · 极速：快速开发、高性能，提升项目交付速度；
> - **R**apid · 高效：低重复编码，业务快速落地；
> - **Man** · 专注于让企业业务系统快速、稳定落地。
> 
> **名字由来**：
> 其实本来是叫artman，是取art design的art和webman的man组合而成，但是artman已经有单位在使用了并且我的域名刚好又被我注册成了atrman，所以就索性改了ARTMAN这个名字。

**Atrman 首先是一个"基座"，其次才是一个后台管理系统。**

它不追求把功能做全，而是先把平台底座做稳：账号、权限、组织、配置、文件、消息这些每个项目都要重复搭建的基础能力，统一沉淀为框架内核；业务功能则以插件形式增量交付，彼此独立。

| 主张 | 含义 |
| --- | --- |
| **框架做窄，插件做宽** | 基座只提供稳定、通用的能力；业务能力都由插件交付，可插拔、可回退。 |
| **不限定业务技术栈** | 插件前端通过 [qiankun](https://qiankun.umijs.org/) 加载插件微应用，Vue3 / React / Angular / 原生 HTML 皆可接入。 |
| **常驻内存内核** | 后端基于 Webman / Workerman 事件循环，长驻内存、支持队列、定时任务与实时推送。 |
| **统一身份，一次登录处处通行** | 一个自然人对应一个全局身份，管理员与会员是同一主体的不同属性，跨端凭票据免登。 |
| **一切扩展可回退** | 插件登记的能力都带归属，卸载即对称清理；扩展机制另有特性开关，关闭后短路为基座默认行为。 |

---

## 二、整体架构

Atrman 由「后端基座 `am-serve`」「基座管理前端 `am-web`」「插件前端框架 `am_plugin` + 插件前端工作区 `am_frontends`」三部分构成，插件作为微应用被基座统一编排；Agent 技能包 `am_skills` 放在同一仓库，完整文档直接看官网 [atrman.com](https://atrman.com)。

```
┌────────────────────────────────────────────────────────────────┐
│ 浏览器 · 生产同域部署 · 共享会话契约                               │
│                                                                │
│   基座管理后台 am-web（qiankun 主应用）    插件前台 /app/{name}   │
│        │  props.hostBridge.invoke() 注入登录态与基座能力         │
│        ▼                                                       │
│   插件微应用（qiankun 子应用 · 不限技术栈）                      │
│   window[{name}] = { bootstrap, mount, unmount }  ← 双入口      │
└───────────────────────────┬────────────────────────────────────┘
        RESTful / JWT       │  /pub/* · /sys/* · /oauth/* · /app/{name}/*
                            ▼
┌────────────────────────────────────────────────────────────────┐
│ 后端基座 am-serve（Webman 常驻内存内核）                          │
│  扩展内核   EventRegistry · ServiceRegistry · AdapterRegistry    │
│             MiddlewareRegistry · TaskRegistry · ChannelAuth      │
│             PluginRegistrar · BasePlugin 生命周期                │
│  系统能力   Auth · RBAC · DataScope · Dept · Config · File ·     │
│             Storage · Notify · Queue · Push · Cron · Backup ·    │
│             Ticket · Store · Upgrade                             │
│  插件运行时 PluginManager 扫描/安装/启用 · qiankun 编排 · 声明式  │
└──────────┬───────────────────┬───────────────────┬──────────────┘
           ▼                   ▼                   ▼
      ┌─────────┐         ┌─────────┐        ┌───────────────────┐
      │  MySQL  │         │  Redis  │        │ 文件存储 本地/OSS/ │
      │  (am_)  │         │         │        │ COS/七牛/S3       │
      └─────────┘         └─────────┘        └───────────────────┘
```

**两类运行角色**

- **基座（Framework）**：交付系统能力与扩展内核，是所有请求、鉴权、编排的中心。
- **插件（Plugin）**：独立目录，自带清单（`manifest.json`）、生命周期（`Plugin.php`）、控制器、视图与前端微应用；被基座扫描、安装、启用、编排。

---

## 三、基座能力

基座自带企业应用所需的基础能力，无需二次开发即可上线。这里只列大致范围与对应入口，具体用法见[官网文档 · 功能使用](https://atrman.com/docs/features/)。

| 领域 | 能力 |
| --- | --- |
| **工作台** | 仪表盘、SFC 远程加载插件小部件（`WidgetController`），跨应用聚合指标 |
| **认证与身份** | JWT 登录鉴权、统一身份（`IdentityService`）、会员体系、SSO 接管、免登票据签发/兑换（`/oauth/ticket/*`、`/oauth/sso/*`） |
| **权限与组织** | RBAC 角色权限、按钮级权限、数据权限（`DataScopeService` + `DataScopeTrait`）、组织架构、菜单管理 |
| **配置中心** | 可视化表单设计器、配置组 + 字段、按应用归属隔离、字典与远程数据统一管理 |
| **文件与存储** | 文件管理、图片处理、分片上传与秒传，本地与多云适配（OSS / COS / 七牛 / S3） |
| **消息与通知** | 邮件 / 短信 / 站内信 / 实时推送多通道，通知模板与发送日志（`/notify/*`） |
| **异步与定时** | Redis 队列、插件信封队列 `plugin_job`、定时任务调度进程、WebSocket 实时推送 |
| **数据交换** | Excel 导入导出（openspout）、模板引擎导出 |
| **运维与审计** | 操作日志、登录日志、服务监控、环境自检、数据备份与还原、基座在线增量升级（`CoreUpgradeService`） |
| **插件应用中心** | 插件分发、版本发布、发布清单与签名校验（`am_store` + `config/store.php`） |
| **国际化** | 后端 `symfony/translation` + 前端 `vue-i18n` ，**暂未适配**|

---

## 四、插件扩展体系

> 插件扩展是 Atrman 的重点。基座开放了从界面到内核的四级扩展通道：插件既可以只加一个页面，也可以接管基座的某个服务。官方提供插件前端框架 `am_plugin`，可快速用 Vue3 构建插件前端。

### 4.1 双入口架构（Dual-Entry）

同一份插件代码，通过清单提供两个访问入口：

| 入口 | 路径 | 打开方式 |
| --- | --- | --- |
| **前台公开入口** | `/app/{name}` | 应用门户新标签直访 |
| **管理入口** | `/app/{name}/manage` | 基座后台菜单经 qiankun 加载 |

基座只负责渲染入口、透传身份，不判定插件内部的权限。某个用户在某个应用里能做什么，由插件自己决定。

### 4.2 多形态插件生态（不限技术栈）

基座对插件只有两条契约：

1. 按 qiankun 规范导出生命周期：`window[{name}] = { bootstrap, mount, unmount }`；
2. 在 `mount(props)` 中通过 `props.hostBridge.invoke(method, params)` 调用基座能力。

| 开发形态 | 说明 |
| --- | --- |
| **Vue3 脚手架（推荐）** | 用官方前端框架 `am_plugin` 装配，自带双入口、免登 SDK、构建自动化 |
| **纯 HTML / jQuery** | 无构建工具，直接写视图，适合轻量工具页与老项目迁移 |
| **React / Angular / Svelte…** | 自维护工程，满足上述两条契约即可接入 |

### 4.3 生命周期与声明式注册

插件的 `Plugin.php` 继承基座 `BasePlugin`，提供完整生命周期钩子（安装、启用、停用、升级、卸载、启动等）。安装/启用后，插件用 `PluginRegistrar` 声明式登记菜单、按钮权限与配置 Schema——登记过程可重复执行，卸载时按应用归属自动清理。骨架模板真源在 `am-serve/stub/plugin/*.stub`。

### 4.4 Bridge 协议：插件调用基座能力

```js
const bridge = props.hostBridge || props.amBridge

await bridge.invoke('auth.getAccessToken')                        // 认证与登录态
await bridge.invoke('data.getDict',  { code: 'structure_type' })  // 字典 / 配置 / 远程数据
await bridge.invoke('picker.file',   { multiple: true, type: 1 }) // 业务选择器
await bridge.invoke('form.render',   { items, model })            // 表单生成 / 渲染
await bridge.invoke('navigation.openPlugin', { name })            // 跨应用导航
await bridge.invoke('ui.confirm',    { title, message })          // UI 反馈
```

能力覆盖 **认证 / 数据 / 选择器 / 表单 / 导航 / UI** 六大类。契约类型见 [`am_plugin/src/bridge/plugin/types.ts`](am_plugin/src/bridge/plugin/types.ts)，基座侧实现见 [`am-web/src/bridge/plugin/`](am-web/src/bridge/plugin)，方法清单文档见 [Bridge 协议](https://atrman.com/docs/plugin/frontend/bridge.html)。

### 4.5 四级扩展通道（扩展内核）

基座把可被插件干预的关键节点抽象为一组注册表（Registry），并记录各自的归属，方便对称卸载：

| 通道 | 机制 | 用途 |
| --- | --- | --- |
| **界面扩展** | qiankun 微应用 + `PluginRegistrar` + 远程小部件 | 加页面、加菜单、加后台配置、加工作台卡片 |
| **行为扩展** | `EventRegistry`（监听 / 触发） | 订阅标准业务事件旁路增强 |
| **能力扩展** | `ServiceRegistry` · `AdapterRegistry` | 按优先级接管 / 装饰基座服务，注册新存储驱动 / 通知通道 |
| **链路扩展** | `MiddlewareRegistry` · `TaskRegistry` · 队列 · 推送 | 织入中间件、声明定时任务、投递异步作业、实时推送 |

这些机制的灰度与回滚入口只有一处：[`am-serve/config/plugin_extend.php`](am-serve/config/plugin_extend.php)。

| 开关 | 默认 | 说明 |
| --- | --- | --- |
| `event_enabled` | `true` | 事件总线；关闭后 `emit` 为空操作 |
| `service_enabled` | `true` | 服务接管；关闭后 `make()` 短路返回默认实现 |
| `middleware_enabled` | **`false`** | 插件中间件织入，安全敏感，仅受信场景灰度开启 |
| `task_enabled` | `true` | 插件定时任务调度进程 |
| `queue_enabled` | `true` | 插件信封队列 `plugin_job` |
| `push_enabled` | `true` | 推送频道鉴权扩展 |
| `composer_enabled` | **`false`** | 插件 Composer 一键安装；开启前必须先填 `composer_allowlist`，否则一律拒绝（fail-closed） |

未开启时基座行为保持原样，扩展只在被注册后生效。

### 4.6 统一身份与免登

- **统一身份**：一个自然人对应一个全局唯一标识，管理员与会员是同一主体的不同属性，跨模块引用只认这一个身份。
- **免登票据**：有效期短、一次性、不可伪造的签名票据，插件可在前后端之间直接换取登录态。
- **跨端会话**：基座与插件同域部署时共享会话契约，打开插件即已登录，一处登出即全局失效（`JwtRevoker` / `JwtKeyGuard`）。

### 4.7 仓库内已带插件

| 插件 | 说明 |
| --- | --- |
| `am_demo` | 官方示例插件：清单、生命周期、控制器与内置视图、前端产物回写、工作台小部件（`public/widget/AppWidget.vue`） |
| `am_member` | 会员体系（含独立会员前端） |
| `am_aibase` | AI 基座：模型接入与配额 |
| `am_store` | 插件应用中心：插件分发、版本发布与升级包，**本插件不开放** |


---

## 五、技术栈

### 5.1 后端（am-serve）

| 领域 | 选型 |
| --- | --- |
| 运行时 | PHP `>= 8.4` |
| 核心框架 | Webman / Workerman `^2.1`（常驻内存 HTTP 服务） |
| ORM / 缓存 | ThinkORM（webman/think-orm）+ webman/think-cache + webman/cache |
| 认证 | tinywan/jwt `^1.11` |
| 事件 / 进程通信 | webman/event · webman/channel |
| 分布式缓存 / 队列 | webman/redis · webman/redis-queue |
| 实时推送 / 定时任务 | webman/push（Pusher 协议）· workerman/crontab |
| 文件存储 | tinywan/storage `^1.1`（本地 / OSS / COS / 七牛 / S3）+ aliyuncs/oss-sdk-php |
| 短信 / 邮件 / 验证码 | overtrue/easy-sms · phpmailer · webman/captcha |
| 校验 / 异常 | topthink/think-validate · monolog/monolog |
| 导入导出 | openspout/openspout（Excel） |
| 唯一标识 / 归属地 | ramsey/uuid + tuupola/base62 · zoujingli/ip2region |
| 版本门禁 / 国际化 | composer/semver（插件版本约束）· symfony/translation |

### 5.2 前端（am-web 基座管理台 / am_plugin 插件框架）

| 领域 | 选型 |
| --- | --- |
| 框架 | Vue `^3.5` + TypeScript `~5.6` |
| 构建 | Vite `^7.1`（+ `vue-tsc` 类型检查） |
| 路由 / 状态 | vue-router `^4.5` · Pinia `^3.0` + persistedstate |
| UI 组件 / CSS | Element Plus `^2.11` + Iconify · Tailwind CSS `^4.1` |
| 微前端 / 远程组件 | qiankun `^2.10` · vue3-sfc-loader |
| 图表 / 富文本 / 多媒体 | ECharts `^6.0` · wangEditor · xgplayer · xlsx |
| 国际化 / 工具 | vue-i18n · @vueuse/core |
| 工程规范 | ESLint + Prettier + Stylelint + Husky + lint-staged + Commitlint + Commitizen |

---

## 六、目录结构

```
atrman/
├── am-serve/                     # ① 后端基座（Webman 服务 · 扩展内核 · 系统能力）
│   ├── app/
│   │   ├── basic/                #   扩展内核：EventRegistry · ServiceRegistry · AdapterRegistry ·
│   │   │                         #   MiddlewareRegistry · TaskRegistry · ChannelAuthRegistry ·
│   │   │                         #   PluginRegistrar · Base* 基类 · JwtKeyGuard/JwtRevoker · concern/
│   │   ├── controller/           #   system/ · notify/ · plugin/（App/Store/Ticket/OAuth/Widget）
│   │   ├── service/              #   Backup · DataScope · Identity · PluginManager · CoreUpgrade ·
│   │   │                         #   Store · ReleaseManifest · Upload · Notify …
│   │   ├── model/                #   ThinkORM 模型（BaseModel 统一时间戳与格式化）
│   │   ├── middleware/           #   鉴权 · 操作日志 · 安全头 · 维护模式 · InstallGuard
│   │   ├── validate/ · exception/ · interface/   # 参数校验 · 异常处理 · 契约接口
│   │   ├── cache/ · event/       #   缓存封装 · 标准业务事件
│   │   ├── queue/ · process/     #   队列消费者 · 常驻进程（推送 / 任务调度 / channel）
│   │   ├── bootstrap/ · view/ · utils/           #   启动钩子 · 视图 · 工具
│   │   └── functions.php         #   全局函数（success / warning / error 统一响应体）
│   ├── config/                   #   配置；plugin_extend.php（扩展开关）· store.php · server.php
│   ├── install/                  #   安装向导资源（install.sql · seed.sql · license.txt）
│   ├── stub/plugin/              #   后端骨架模板真源（基座"创建插件"从此生成）
│   ├── public/                   #   管理前端构建产物回写位置（assets 随仓库提交）
│   ├── support/                  #   Webman 支撑层
│   ├── plugin/                   #   ⚠ 插件目录（am_demo · am_member · am_store · am_aibase）
│   ├── manifest.json             #   ⭐ 基座版本与运行要求真源
│   ├── start.php · windows.php   #   Linux/macOS 启动入口 · Windows 开发入口
│   └── Dockerfile · docker-compose.yml
│
├── am-web/                       # ② 基座管理后台前端（Vue3 + qiankun 主应用）
│
├── am_plugin/                    # ③ 插件前端开发框架真源（装配到各插件工作区）
│   └── src/{api,bridge,sdk,store,router,views,components,hooks} · scripts/post-build.ts
│
├── am_frontends/                 # ④ 插件前端工作区装配根目录
│   ├── create-plugin.mjs         #   ⭐ 装配脚本（框架提供的开发工具，见下）
│   └── am_demo   # 已装配的真实工作区，插件的前端就在这里进行开发
│
├── am_skills/                    # ⑤ 官方 Agent 技能包（am_plugin_dev · am_dev_standard）
│
├── 开发规范.md                    # 基座开发规范真源
├── 发布打包说明.md                 # release.php 用法与产物约定
└── 创建插件脚手架-win版.bat|.sh    # create-plugin.mjs 的启动器
```

### `am_frontends/create-plugin.mjs`

框架提供的**插件前端装配脚本**（Node ≥ 20.19，原生 ESM、无需 `package.json`），另有根目录启动器 `创建插件脚手架-win版.bat` / `创建插件脚手架.sh`。

- **职责边界**：后端骨架全部由**基座后台**的「创建 / 重新生成自定义插件」生成；本脚本不生成任何 PHP，只把前端框架真源 `am_plugin` 装配到独立前端工作区 `am_frontends/{name}/`。
- **前置校验**：目标插件的 `am-serve/plugin/{name}/manifest.json` 必须已存在；插件名须匹配 `am_[a-z][a-z0-9_]*`（如 `am_demo`）；工作区不可重复。
- **落位机制**：前端源码不进插件目录，构建产物按 `.env` 的 `VITE_PLUGIN_ROOT` 自动回写到插件目录，插件打包不带冗余源码与 `node_modules`。

```bash
# 前置：先在基座后台创建同名插件（生成后端骨架）
node am_frontends/create-plugin.mjs am_demo
cd am_frontends/am_demo
pnpm install
pnpm dev      # 本地开发（默认 3007，代理到 8787）
pnpm build    # 构建并自动回写插件目录
```

---

## 七、快速开始

### 环境要求

| 环境 | 最低 | 推荐 | 说明 |
| --- | --- | --- | --- |
| PHP | ≥ 8.4 | 8.4 | 需 `pdo_mysql` `redis` `openssl` `zip` `gd` `fileinfo` |
| MySQL | ≥ 8.0 | 8.0+ | 向导可自动建库，表前缀 `am_` |
| Redis | ≥ 5.0 | 7.x | 缓存 / 会话 / 队列 |
| Node.js | ≥ 20.19.0 | 20.x LTS | 仅源码开发方式需要 |
| pnpm | ≥ 8.8.0 | 9.x | 仅源码开发方式需要 |

```bash
php -m | grep -Ei 'pdo_mysql|redis|openssl|zip|gd|fileinfo'   # 扩展自检
```

### 启动（源码方式）

```bash
# 后端基座
cd am-serve
composer install
php start.php start          # Linux / macOS（生产加 -d）
php windows.php              # Windows 开发

# 基座管理前端
cd am-web
pnpm install
pnpm dev                     # 开发（Vite，默认 3006）
                             # 本地联调：把 .env.development 的 VITE_API_PROXY_URL 指向 http://127.0.0.1:8787
pnpm build                   # vue-tsc 类型检查 + vite build + post-build 回写 am-serve/public
```

服务默认监听 `:8787`。启动后访问 `http://127.0.0.1:8787`，**由安装向导完成初始化**（环境检测 → 建库建表 → 写 `.env` → 创建管理员）；请勿手动改 `.env` 或导入 SQL。

> 另两种方式：**发行包一键部署**（包内已含 `vendor/` 与前端产物，解压即跑，推荐生产）、**宝塔面板部署**（建站 + 反代 + 进程守护）。详见 [部署后端基座](https://atrman.com/docs/install/backend.html)。仓库内另含 `am-serve/Dockerfile` 与 `docker-compose.yml` 可自行构建。

### 开发一个插件（端到端）

```text
1. 基座后台「创建自定义插件」 → 由 stub/plugin 模板生成后端骨架
2. 装配前端                  → node am_frontends/create-plugin.mjs am_demo
3. 开发业务                  → cd am_frontends/am_demo && pnpm install && pnpm dev
4. 构建产物                  → pnpm build（自动回写 am-serve/plugin/am_demo）
5. 扫描 / 安装 / 启用         → 基座后台「插件管理」，随后从菜单或门户打开
```

---

## 八、仓库工具脚本

| 脚本 | 用途 |
| --- | --- |
| `am_frontends/create-plugin.mjs` | 把 `am_plugin` 装配为独立插件前端工作区（见 [§6](#六目录结构)） |
| `创建插件脚手架-win版.bat` / `创建插件脚手架.sh` | 上一条的图形化启动器，无参时提示输入插件名 |
| `am_skills/install.sh` / `install.bat` | 把官方 Agent 技能装进 AI 编程助手的技能目录（`project` / `user` / 自定义路径） |

---

## 九、文档地图

全部文档以官网 [atrman.com](https://atrman.com) 为准，本 README 不再重复介绍，按需要点进对应栏目即可：

| 栏目 | 内容 |
| --- | --- |
| [介绍](https://atrman.com/docs/intro/) | 核心概念、整体架构、技术栈、FAQ |
| [安装部署](https://atrman.com/docs/install/) | 三种部署方式、安装向导、后台初始化、目录结构、生产部署 |
| [功能使用](https://atrman.com/docs/features/) | 认证、RBAC、组织、菜单、字典配置、文件存储、通知、队列任务、推送、监控、审计备份、升级、仪表盘 |
| [插件 · 概念](https://atrman.com/docs/plugin/concept/) | 插件结构、清单、生命周期、双入口、安装与升级 |
| [插件 · 后端](https://atrman.com/docs/plugin/backend/) | 控制器路由、模型服务、注册器、事件、服务接管、中间件、队列任务推送、存储/短信/身份扩展、会员鉴权 |
| [插件 · 前端](https://atrman.com/docs/plugin/frontend/) | 总览、脚手架、Bridge、SDK、CRUD、纯 HTML、SSO、工作台小部件 |
| [插件 · 实战](https://atrman.com/docs/plugin/cookbook/) | `am_demo` 全流程、最小 HTML 插件、发布前检查清单 |
| [开发规范](https://atrman.com/docs/standards/) | 后端、前端、数据库、API、权限、缓存、代码质量 |
| [附录](https://atrman.com/docs/appendix/) | 响应码、术语表、更新日志、贡献指南 |

**文件说明**

| 文件 | 说明 |
| --- | --- |
| [`开发规范.md`](开发规范.md) | 基座本体（`am-serve` / `am-web`）开发规范**真源**；线上「开发规范」栏目是它的拆分版 |
| `发布打包说明.md` | `release.php` 发版流程约定、用法、产物结构、上传与边界（仅私有库） |
| [`am_skills/`](am_skills) | 官方 Agent 技能包：`am_plugin_dev`（插件全流程开发）、`am_dev_standard`（基座开发规范） |

---

## 十、API 与响应规范

统一响应体（前端拦截器据此分流，键名是 `msg` 与 `page`）：

```jsonc
{ "code": 200, "msg": "操作成功", "data": {}, "total": 0, "page": 1 }
```

`code`：`200` 成功 · `400` 业务/参数错 · `401` 未登录 · `403` 无权限 · `500` 服务器错。

| 前缀 | 用途 | 鉴权 |
| --- | --- | --- |
| `/pub/*` | 公开接口（`/pub/auth` 登录与验证码、`/pub/system` 系统信息/当前用户/菜单/字典/上传） | 免登录 |
| `/sys/*` | 基座系统管理 | 登录 + `#[Permission]` |
| `/notify/*` | 通知中心 | 登录 + RBAC |
| `/plugin/*` | 插件管理与应用插件 | 登录 + RBAC |
| `/oauth/*` | 免登票据与 OAuth 2.0 / SSO | 见各端点 |
| `/app/{name}/*` | 插件页面入口与业务接口 | 插件自行判定 |
| `/api/install` | 安装向导 | 安装期专用守卫 |

路由全部由控制器注解注册（类级 `#[RouteGroup]` + 方法级 `#[Get]` / `#[Post]`），`config/route.php` 不设默认路由。接口以「动作式子路径」组织（`/index` `/read` `/save` `/update` `/delete` `/batchDelete` `/changeStatus`），读用 GET、写用 POST。详见 [API 接口规范](https://atrman.com/docs/standards/api.html)。

---

## 十一、官网与社区

| 入口 | 地址 |备注 |
| --- | --- |--- |
| 🛠 官网 | https://atrman.com | 主要是文档介绍|
| 🚀 在线演示 | https://demo.atrman.com |账号：demo 密码：demo123|
| 🧩 插件应用中心 | https://apps.atrman.com | 支持更新中... |
| 🐙 Gitee | https://gitee.com/raingad/atrman | MIT开源地址 |

> 演示环境为体验账号（仅演示，请勿操作违法数据）：`demo / demo123`。

**QQ 交流群**：`933351899`，点击加入 → [ATRMAN 交流群](https://qm.qq.com/q/bFOcPiLBLy)（使用答疑、新版本发布、需求讨论）

---

## 十二、贡献与许可

- 后端遵循 PSR-12，前端遵循项目 ESLint / Prettier / Stylelint；提交信息遵循 Conventional Commits（配 Commitizen + commitlint 校验）。
- 改基座或插件前请先读 [`开发规范.md`](开发规范.md)，或安装 `am_skills/am_dev_standard` 让 AI 助手按同一套规范干活。
- 本项目基于 [MIT License](am-serve/LICENSE) 开源。
- 本项目使用过 AI 辅助开发，历时半年打磨。

<div align="center">

**Atrman，把底座做稳，把业务交给插件。**

如果觉得有帮助，欢迎点个 ⭐ Star。

</div>
