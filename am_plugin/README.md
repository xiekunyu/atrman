# Atrman 插件前端开发框架（am_plugin）

`am_plugin` 是 Atrman 基座体系的**插件前端开发脚手架**，基于 `Vue 3 + TypeScript + Vite 7 + Element Plus + qiankun` 构建。它不是业务插件本身，而是「插件前端开发框架」：通过装配脚本把整套框架装配到**独立前端工作区** `am_frontends/{name}/`，与插件目录解耦；构建产物再通过 `.env` 的 `VITE_PLUGIN_ROOT` 回写到对应插件目录。

配套后端骨架（`manifest.json / Plugin.php / config / 控制器 / 视图`）由**基座后台**的「创建 / 重新生成自定义插件」功能生成，模板真源在 `am-serve/stub/plugin/*.stub`。本脚手架**不生成任何 PHP**。

**文档分工**：本文是**前端脚手架操作手册**（装配流程、SDK、双入口开发规范、构建落位、调试、FAQ）；插件系统级契约（manifest 字段、安装升级、生命周期、后端路由规范、Bridge 协议、身份免登体系、HTML/jQuery/React 等其它形态接入）以仓库根目录 [插件开发文档.md](插件开发文档.md) 为唯一真源，本文相关处只做链接不重复维护。

---

## 1. 核心架构：双入口 · 单一真源

### 1.1 双入口架构

一个插件工程同时产出两个入口，**一次 `pnpm build`、共用同一份 bundle**：

| 入口 | URL | 形态 | 路由树 | 登录 / Bridge |
|------|-----|------|--------|---------------|
| 管理入口 manage | `/app/{name}/manage` | 基座 qiankun 内打开（免登 + 菜单驱动） | `views/manage` 布局 + 菜单动态路由 | 依赖基座 |
| 前端入口 front | `/app/{name}` | **公开访问**，可直连、可选从基座打开 | `/index/**` 静态路由（`views/index`） | 内置免登：同域会话/票据自动登录（零对接，见 §11.8）；无登录也可公开浏览 |

典型场景（CMS）：后台在 manage 入口管理文章内容；`/app/am_cms` 是任何人都能访问的官网前台，不需要登录、不需要从基座打开、不依赖 Bridge 能力。

### 1.2 单一真源约定

- **后端骨架真源**：基座后台功能生成，模板在 `am-serve/stub/plugin/*.stub`；
- **前端框架真源**：本仓库 `am_plugin/`，由装配入口 `am_frontends/create-plugin.mjs`（可双击 仓库根目录的 `创建插件脚手架.bat` / `创建插件脚手架.sh`）从 `am_plugin` 拷贝到独立工作区 `am_frontends/{name}/`（前后端解耦，源码不进插件目录）；
- 两边的职责边界由装配脚本强制：装配脚本只拷贝前端源码与配置，绝不覆盖 PHP。

---

## 2. 环境要求

| 依赖 | 版本要求 | 说明 |
|------|----------|------|
| Node.js | >= 20.19.0 | `package.json engines` 强制 |
| pnpm | >= 8.8.0 | 仅支持 pnpm（含 lockfile 同步机制） |
| 基座后端 | am-serve（Webman） | 默认监听 `http://localhost:8787` |
| 浏览器 | 支持 ES2015+ | 构建 target 为 `es2015` |

---

## 3. 标准开发流程（从零到部署）

### 第 1 步：在基座后台创建插件（生成后端骨架）

登录基座管理后台 → 插件管理 → 「创建自定义插件」，填写插件名（必须 `am_` 前缀，如 `am_demo`）。**插件需要会员登录能力时，勾选表单中的「启用会员登录能力」**（要求 am_member 已安装并启用），保存后 manifest 会写入 `"dependencies": ["am_member"]`，启用时基座强制校验该依赖、禁用/卸载 am_member 时反向拦截；改依赖后需**重载插件**让 manifest 生效。基座会在 `am-serve/plugin/{name}/` 生成：

```text
am-serve/plugin/am_demo/
├── manifest.json                 # 插件清单（entry / manage_entry / open_mode 等）
├── Plugin.php                    # 插件生命周期钩子
├── config/
│   ├── app.php
│   ├── route.php                 # 页面入口路由（需手动维护，见 §8.1）
│   ├── static.php
│   └── view.php
├── app/
│   ├── controller/AppController.php   # 双入口控制器骨架
│   └── view/app/index.html、manage.html  # 占位视图（pnpm build 后被覆盖为真实视图）
└── public/                       # 静态资源落地目录（assets/ 由构建写入）
```

> 已存在的插件可点「重新生成模板」补齐/修复骨架。装配脚本会检查 `manifest.json` 是否存在，缺失则拒绝执行。

### 第 2 步：装配前端框架

在 `am_frontends` 目录执行（或直接双击 仓库根目录的 `创建插件脚手架.bat` / `创建插件脚手架.sh`）：

```bash
cd am_frontends
node create-plugin.mjs am_demo     # 或双击根目录「创建插件脚手架」启动器
```

脚本从框架真源 `am_plugin` 拷贝 `src/`、`scripts/`、`public/` 及全部配置文件装配到**独立工作区** `am_frontends/am_demo/`（与插件目录平级，不再内嵌），并自动生成三份环境变量文件（`.env` / `.env.development` / `.env.production`，插件名、base 路径、**`VITE_PLUGIN_ROOT`**（指向插件根的相对路径）均已填充）。同时携带 `pnpm-lock.yaml` 锁定依赖版本——**不要删除**，否则 `pnpm install` 会按 `^` 范围解析到更新的小版本（如 element-plus 2.11 → 2.14），引发 vue-tsc 类型漂移等构建失败。

> 因前端源码不再进入插件目录，备份/打包插件时天然不会带上冗余的前端工程与 `node_modules`；`pnpm build` 会依据 `VITE_PLUGIN_ROOT` 把产物写回插件目录。

### 第 3 步：安装依赖、启动开发

```bash
cd am_frontends/am_demo
pnpm install
pnpm dev        # https://localhost:3007
```

开发调试在**独立运行模式**下进行（不进基座也可预览两个入口，见 §6）。若要调测「基座内 qiankun 加载」形态，把 `manifest.json` 临时改为：直接在基座的插件管理中修改地址即可；

```json
{
  "entry": "http://localhost:3007",
  "manage_entry": "http://localhost:3007",
  "open_mode": "micro",
  "is_dev": 1
}
```

### 第 4 步：构建产物、注册页面入口

```bash
pnpm build
```

build 命令实际串联三件事：`vite build && vue-tsc --noEmit && tsx scripts/post-build.ts`。产物去向：

| 产物 | 位置 | 说明 |
|------|------|------|
| JS / CSS / 图片 / 字体 | `plugin/{name}/public/assets/{js,css,img,fonts}/` | 两入口共用同一份 UMD bundle（vite `outDir` 据 VITE_PLUGIN_ROOT 直写插件） |
| 管理入口视图 | `plugin/{name}/app/view/app/manage.html` | 以构建出的 index.html 为模板，注入 `window.__PLUGIN_ENTRY__='manage'` |
| 前台入口视图 | `plugin/{name}/app/view/app/index.html` | 注入 `window.__PLUGIN_ENTRY__='front'` |

产物与视图的输出目录统一由前端工作区 `.env` 的 `VITE_PLUGIN_ROOT` 定位（指向插件根）；也可用 `--plugin/--assets/--views` 临时覆盖。

### 第 5 步：确认生产 manifest

```json
{
  "entry": "/app/am_demo",
  "manage_entry": "/app/am_demo/manage",
  "open_mode": "micro"
}
```

### 第 6 步：基座内注册菜单并访问

- 管理入口：基座后台菜单配置指向插件，点菜单即通过 qiankun 免登打开；
- 前台入口：任何人直接访问 `/app/am_demo`。

> **前台免登（内置、零对接）**：`am_plugin` 前台独立入口会自动「本地优先读同域 `atrman:session`，读不到再用门户签发的一次性票据兑换主 JWT」，用 `useUser()` / `useUserStore()` 即可拿到登录态，开发者无需任何对接代码；未登录的公开页照常可浏览。完整契约与安全边界见《插件开发文档.md》§11.7、§11.8。

---

## 4. 目录结构

```text
am_frontends/{name}/             # ← 由 create-plugin.mjs 从 am_plugin 装配的独立前端工作区
├── src/
│   ├── sdk/                     # ★ 插件 SDK（开发者主要打交道的一层）
│   │   ├── bridge.ts            #   基座 Bridge 持有与 invoke
│   │   ├── entry.ts             #   入口模式解析（manage / front）
│   │   ├── http.ts              #   请求实例（自动带 token）
│   │   ├── user.ts              #   用户状态（免登获取）
│   │   ├── theme.ts             #   主题跟随基座
│   │   └── index.ts             #   统一出口：import { ... } from '@/sdk'
│   ├── bridge/plugin/types.ts   # Bridge 协议类型定义
│   ├── views/
│   │   ├── index/               #   前台公开页面（/index/**，无需登录，入口视图 index.html）
│   │   └── manage/              #   后台管理布局与页面（菜单驱动，需登录，入口视图 manage.html）
│   ├── api/
│   │   ├── index/               #   前台公开接口层（只调 $noNeedLogin 白名单）
│   │   ├── system/              #   基座系统接口（用户/字典/文件等，按需裁剪）
│   │   └── plugin/              #   本插件管理接口
│   ├── router/
│   │   ├── modules/index.ts     #   前台静态路由树（indexRoutes，/index/**）
│   │   ├── modules/manage.ts    #   后台默认回退菜单（manageRoutes，无后端菜单时使用）
│   │   ├── core/                #   菜单驱动路由引擎（MenuProcessor → RouteRegistry）
│   │   └── guards/              #   登录/权限守卫（front 模式自动放宽）
│   ├── components/              #   core 通用组件 + business 业务组件（am-* 系列）
│   ├── store/                   #   Pinia（含持久化）
│   ├── hooks/ / utils/ / types/ #   组合式函数 / 工具 / 类型
│   ├── assets/styles/           #   SCSS（mixin 已通过 vite 全局注入）
│   ├── locales/                 #   vue-i18n 语言包
│   ├── main.ts                  #   qiankun 生命周期 + 入口模式解析
│   └── App.vue
├── scripts/
│   └── post-build.ts            #   构建后：生成双入口视图 + 产物落位
├── index.html                   #   唯一构建入口（双视图由它派生）
├── vite.config.ts / tsconfig.json / .env* / package.json
└── pnpm-lock.yaml               #   ★ 版本锁定，必须保留
```

路径别名：`@`→`src/`、`@styles`、`@utils`、`@views`、`@imgs`。
`vue / vue-router / pinia / @vueuse/core / Element Plus` 已配置自动导入（unplugin-auto-import + unplugin-vue-components），页面中无需手写 import。

---

## 5. SDK 使用指南（`@/sdk` 统一出口）

### 5.1 获取当前用户（自动免登）

```vue
<script setup lang="ts">
import { useUser } from '@/sdk'
const { user, loading } = useUser()
</script>
<template>
  <div v-if="!loading">当前用户：{{ user?.nickname }}（SID: {{ user?.sid }}）</div>
</template>
```

### 5.2 请求后端接口

底层复用 `@/utils/http`，token 在 mount 时已从基座同步到 store，请求自动携带 `Authorization` 头：

```ts
import { request } from '@/sdk'

const data = await request.get('/app/am_demo/list')          // GET
const res  = await request.post('/app/am_demo/save', { name: 'test' }) // POST
```

### 5.3 调用基座能力（Bridge）

```ts
import { invoke, hasBridge } from '@/sdk'

await invoke('ui.message', { type: 'success', message: '操作成功' })
const dict = await invoke('data.getDict', { code: 'structure_type' })
const files = await invoke('picker.file', { multiple: true, type: 1 })
```

**front 公开页必须判空**：直连访问没有 bridge，调用前检查 `hasBridge()`，或做未登录降级 UI。

### 5.4 入口模式 API

```ts
import { getEntryMode, isFrontEntry } from '@/sdk'
if (isFrontEntry()) { /* 前台公开形态 */ }
```

解析优先级（`src/sdk/entry.ts`）：`props.entryType`（基座 qiankun 传入）→ `window.__PLUGIN_ENTRY__`（入口视图 HTML 注入）→ 独立运行 hash 前缀 `#/index` → 默认 `manage`。front 模式下：不拉菜单、根路径重定向 `/index/home`、401 只做门控翻牌 + 防抖通知（不自动跳走）、主题在基座内时跟随基座。

### 5.5 会员中心对接（登录 / 注册 / 个人中心）

插件不自建登录页，统一对接会员中心 `am_member`。登录 / 注册是**同页签票据闭环**（跳走 → 成功后整页带回当前 hash 深链），个人中心是**新标签**跨应用浏览。全部从 `@/sdk` 导出（实现见 `src/sdk/member.ts`）：

```ts
import {
  openMemberLogin,      // 同页签跳会员登录页，默认带回当前 hash 深链
  openMemberRegister,   // 同页签跳会员注册页（注册即登录，回跳协议一致）
  openMemberCenter,     // 新标签打开个人中心 /index/center/home
  requireLogin,         // 动作守门：未登录自动跳登录并返回 false
  isLoggedIn,
  logoutLocal,          // 清跨端统一会话；同域各标签 storage 事件同步登出
  onUnauthorized        // 订阅 401 事件（<RequireLogin> 内部已用）
} from '@/sdk'

const onSubmit = () => {
  if (!requireLogin()) return   // 未登录时跳登录，返回 false 中止动作
  // ...已登录业务
}
```

页面级门控用内置组件 `src/components/RequireLogin/index.vue`：未登录就地显示「登录 / 免费注册 / 我已在其他标签完成登录」，401 后自动翻牌，无需刷新：

```vue
<RequireLogin title="登录后查看我的订单" :inline="false">
  <MyOrders />
</RequireLogin>
```

环境变量 `VITE_MEMBER_ENTRY`（三套 `.env*` 均已内置，默认 `/app/am_member`）只允许指向同域地址；前台 HTTP 401 的统一行为是「清会话 + 门控翻牌 +（曾登录时）防抖通知带重新登录按钮」，不在拦截器里自动整页跳走。完整链路与协议白名单见《插件开发文档.md》§11.9 / §11.11，后端配套 `MemberAuth` trait 见 §11.10。

---

## 6. 本地调试矩阵

| 目标 | 方式 |
|------|------|
| 前台页面 | `pnpm dev` 后访问 `https://localhost:3007/#/index/home`（无 bridge，等同线上公开形态） |
| 后台页面 | `https://localhost:3007/#/manage/home` |
| 基座内调前台 | manifest `entry` 指向本地 dev URL，基座打开时传 `props.entryType='front'` |
| 基座内调后台 | manifest `manage_entry` 指向本地 dev URL |

dev server 已内置代理：`/api` → 后端（`VITE_API_PROXY_URL`，默认 `http://localhost:8787`）、`/plugin` → 后端；启用 HTTPS（basic-ssl），首次访问浏览器信任自签证书即可。

> **登录闭环只能在同域形态下联调**：`VITE_MEMBER_ENTRY` 默认 `/app/am_member`，会员会话依赖同域 localStorage 契约；跨端口（3007 ↔ 8787）时 localStorage 不共享，闭环无法走完全程，纯 UI 开发只验证跳转 URL 与参数即可，完整链路用同域 host/反向代理（或直接在基座装配后的工作区）验证。

---

## 7. 页面开发规范

### 7.1 管理后台页面（菜单驱动路由）

路由由菜单数据动态注册（`MenuProcessor → RouteRegistry`），无后端菜单时回退使用 `src/router/modules/manage.ts` 的 `manageRoutes`：

1. 新建页面组件，如 `src/views/my-page/index.vue`；
2. 在 `manage.ts` 的 `manageRoutes.children` 中登记：

```ts
{
  path: 'my-page',
  name: 'MyPage',
  component: '/my-page/index',   // 相对 src/views/ 的字符串路径，可省略 index
  meta: { title: '我的页面', icon: 'ri:file-line', keepAlive: true }
}
```

> `component` 由 `ComponentLoader` 解析字符串路径，**不是 `import()`**。布局容器固定 `/manage/index`。正式项目由基座后台下发菜单时，`manage.ts` 仅作默认回退。

### 7.2 前台公开页面

在 `src/views/index/` 建页面，并在 `src/router/modules/index.ts` 的 `children` 追加**静态路由**（标准 `import()` 写法），访问路径 `/index/xxx`，参考 `home` / `detail` 示例页。页面内会员内容用 `<RequireLogin>` 门控（见 §5.5），动作按钮用 `requireLogin()` 守门。

### 7.3 开发分离硬约定

- **目录**：`views/index/**` = 公开前台；其余 views + 菜单驱动 = 管理后台；
- **接口分层**：`api/index/**` 只允许调用后端 `$noNeedLogin` 白名单内的接口（含公开接口与「白名单 + 方法内 requireMember 自持鉴权」的前台会员接口，后者匿名访问返回业务形 401，只能在 `<RequireLogin>` 门控内调用）；前台组件禁止 import 需登录的管理接口层；
- **依赖规则**：front 页面禁止 import 后台强绑定模块（menu store、worktab、菜单守卫）；共享层只允许 `sdk/http`、`sdk/bridge`（必须 `hasBridge()` 判空）、`sdk/user`、`utils/*`、通用 `components/*`；
- **登录态可选**：前台依赖登录的功能必须条件渲染 + 未登录降级 UI，标准做法是 `<RequireLogin>` / `requireLogin()`（见 §5.5，参考 `views/index/home/index.vue`）；**禁止**自建登录页或在 front 路由守卫里做自动跳转（守卫不消费 `meta.requiresAuth`）。

---

## 8. 后端配合要点

### 8.1 页面入口路由（必须手动注册）

注解无法生成无尾斜杠路径，页面入口在插件 `config/route.php` 手动注册：

```php
Route::get('/app/am_demo', [AppController::class, 'index']);        // 前台入口 → view('app/index')
Route::get('/app/am_demo/manage', [AppController::class, 'manage']); // 管理入口 → view('app/manage')
```

### 8.2 业务接口规范

- 类级 `#[RouteGroup('/app/{name}')]` + 方法级 `#[Get/Post]`；
- `/app/{name}` 是页面入口与业务接口的统一 url 前缀；`plugin/{name}/...` 是物理目录路径（静态资源经 static 映射以 `/app/{name}/assets/...` 访问），**不要**当接口前缀用；
- 管理接口默认需登录（AuthCheck 中间件）；**前台公开接口的方法名必须加入 `$noNeedLogin` 白名单**；
- 前台「需登录」接口同样先加白名单（绕过 AuthCheck），方法内首行用基座 `MemberAuth` trait 的 `requireMember()` 自持鉴权，匿名访问返回统一业务形 401（HTTP 401，`{code:401,...}`），交由前端 `<RequireLogin>` 门控；不要在前台控制器里自建登录校验；
- 控制器多了按**鉴权边界**拆目录：`admin/`（后台 RBAC）、`public/`（纯匿名）、`api/` 与 `developer/`（自持验签、绕 AuthCheck）、`widget/`（小部件）；简单插件一个根控制器足矣。具体控制器**不加 `final`、也不继承别人的具体控制器**，复用只继承抽象基类；
- 自持验签链路（`api/`、`developer/` 目录自己 `JwtToken::verify()`）验签后必须补查 `JwtRevoker::isRevoked($extend['jti'] ?? '')`，否则用户登出后旧 JWT 在过期前仍能打穿；走全局 AuthCheck 的链路黑名单已统一查，不用自己补；
- 完整后端规范（路由/注解/生命周期/安装升级）以《插件开发文档.md》§2–§3 为准，三种鉴权姿势与 `MemberAuth` 方法表见《插件开发文档.md》§11.10、《后端插件开发文档.md》§5.5。

```php
#[RouteGroup('/app/am_demo')]
class AppController
{
    use \app\basic\MemberAuth;   // 基座提供，脚手架骨架已内置

    protected array $noNeedLogin = ['index', 'manage', 'publicPosts', 'mineInfo'];

    public function index(): Response  { return view('app/index'); }
    public function manage(): Response { return view('app/manage'); }

    #[Get('/public/posts')]  // GET /app/am_demo/public/posts，供 views/index 前台调用
    public function publicPosts(): Response { /* ... */ }

    #[Get('/mine/info')]     // 姿势②：白名单 + 方法内自持会员鉴权（前台会员接口）
    public function mineInfo(Request $request): Response
    {
        if ($r = $this->requireMember($request)) return $r;   // 未登录 → 业务形 401
        $ctx = $this->memberContext($request);                // sid/account_type/is_admin…
        return json(['code' => 0, 'msg' => 'ok', 'data' => $ctx]);
    }

    #[Get('/list')]          // 姿势③：需登录（不在白名单，AuthCheck 拦截），管理接口用
    public function list(Request $request): Response { /* ... */ }
}
```

> 改完白名单/注解后注意 Webman 反射缓存（AuthCheck 缓存控制器属性，需清缓存/重启；基座「重新生成模板」链路会自动清 ReflectionCache）。

---

## 9. 免登链路（前端视角，仅 manage 入口 / 基座内打开的 front 入口）

插件经 qiankun 加载后，基座注入 `props.hostBridge`；SDK 在 mount 时通过 `auth.getUserProfile` 获取用户并把 token 同步进 store，后续请求由 HTTP 拦截器自动注入 `Authorization: {token}`，后端 AuthCheck 验 token——插件代码全程不碰认证逻辑。

> 基座侧的统一主体身份（sid）、ticket 签发/消费、IdentityService 等完整免登体系见《插件开发文档.md》§11。
>
> 前台公开入口直连时没有 bridge/token 是**正常状态**：公开内容直接浏览；需要登录时由 §5.5 的同页签票据闭环带去 am_member、成功后签一次性 ticket 整页带回（见《插件开发文档.md》§11.9），本 SDK 的 `bootstrapStandaloneAuth` 负责消费 ticket 并落回原 hash 深链。

---

## 10. Bridge 能力清单

Bridge 是基座对所有插件形态（脚手架 / HTML / React 等）统一暴露的能力协议，**方法清单、参数示例与 scope 定义以《插件开发文档.md》§4 为唯一真源**，本处不重复维护。

脚手架形态下不需要直接操作 `props.hostBridge`：从 `@/sdk` 导入 `invoke / hasBridge / getCapabilities` 即可调用全部 Bridge 能力（用法见 §5.3）。特别提醒：**front 公开页必须 `hasBridge()` 判空**，直连访问时没有 bridge，调用前需降级处理。

---

## 11. 构建与部署细节

- **UMD 单 bundle**：`rollupOptions.output.format='umd'`、`name=VITE_PLUGIN_NAME`，qiankun 要求；
- **产物分类**：`assets/js|css|img|fonts/` 四级目录由 `assetFileNames/chunkFileNames` 规则生成，HTML 模板引用带 hash 文件名；
- **base 路径**：生产为 `/app/{name}/assets/`（`.env.production` 已自动生成），开发为 `/`；
- **产物落位（前后端解耦）**：`vite.config.ts` 的 `outDir` 与 `post-build.ts` 均以 `.env` 的 `VITE_PLUGIN_ROOT`（指向插件根）为基准，把 `public/assets` 与 `app/view/app` 双视图直写到插件目录；缺失时构建直接报错（不再回落本地）。
- **terser**：构建自动 `drop_console / drop_debugger`；
- **类型检查参与构建**：`vue-tsc --noEmit` 失败即构建失败，提交前先跑 `pnpm type-check`。

---

## 12. 常见问题（FAQ）

| 现象 | 原因与处理 |
|------|------------|
| `create-plugin.mjs` 报「未找到插件后端」 | 未先在基座后台创建同名插件；先建插件（或「重新生成模板」）再装配 |
| 报「前端工作区已存在」 | 重新装配需先删除 `am_frontends/{name}/` |
| 直接在 `am_plugin` 根目录 `pnpm build` 报「缺少 VITE_PLUGIN_ROOT」 | 预期行为：脚手架目录自身不构建，只在 `am_frontends/{name}/` 工作区构建（那里才有 VITE_PLUGIN_ROOT） |
| 插件目录 `pnpm build` 类型校验失败（element-plus 等） | 装配时必须携带 `pnpm-lock.yaml`，缺失导致版本漂移；重新装配并保留 lockfile |
| `auto-imports.d.ts` 相关报错 | 该文件为构建自动生成（已 gitignore），跑一次 `pnpm dev` 或 `pnpm build` 即生成 |
| 构建后基座页面白屏 / 404 | 检查 `config/route.php` 是否注册了两个无尾斜杠页面入口；检查 manifest `entry/manage_entry` 是否为 `/app/{name}` 与 `/app/{name}/manage` |
| 其他标签登出后，本插件还显示在线 | 跨标签同步只靠 `atrman:session` 的 storage 事件：是否用了脚手架内置的 `setupSessionSync()` 监听与 `clearSession()` 登出；自建 store、绕开 SDK 就不会收到翻牌广播（同标签内改动不触发 storage 事件是浏览器机制） |
| 用户登出后旧 token 还能调通接口 | 自持验签链路漏查吊销名单：verify 后补 `JwtRevoker::isRevoked()`，见 §8.2 |
| 前台接口 401 | 两种含义：① 公开接口的方法名未加入 `$noNeedLogin` 白名单（或改注解后未清反射缓存/重启）；② 姿势②会员接口的正常业务响应——匿名访问 `requireMember()` 即返回业务形 401，前端清会话 + `<RequireLogin>` 翻牌，曾登录时另有防抖通知，**不会**自动整页跳走 |
| 点登录后停在会员页提示「返回来源应用失败」 | 目标插件被停用/卸载或 manifest 异常，会员端签 ticket 失败；此时可在会员页直接登录，会员内容不受影响。恢复插件后重试即可 |
| 基座内（qiankun/iframe）点登录后整个框架跳到了插件整页 | 预期行为：同页签闭环是顶层导航，回跳地址只信任服务端 manifest 的 entry，落地为插件独立整页；嵌入态不做保框架改造 |
| 跨端口 dev 下登录闭环回不来 | localStorage 不跨端口共享（见 §6 备注）；用同域 host/反代联调，或仅验证生成的跳转 URL |
| 插件登录和基座后台 am_web 的 SSO 是一套吗 | 不是。插件走基座 `/oauth/ticket/issue` + `/oauth/ticket/exchange` 票据通道；am_web 后台 SSO 是 am_member 的独立白名单通道，二者互不复用，插件不要尝试对接 SSO 接口 |
| 已在别的标签登录，门控还显示未登录 | 点门控里的「我已在其他标签完成登录」刷新页面；正常同页签闭环回跳会自动水合，无需手动 |
| 改完插件名/目录后产物没写对位置 | 确认 `am_frontends/{name}/.env` 里 `VITE_PLUGIN_ROOT` 指向的插件根存在且路径正确（由 create-plugin.mjs 生成，勿手改） |
| 在 `am_plugin` 根目录出现 `app/view/app/*.html` | 旧版回落产物（已移除该机制）；若残留可删除，正式产物只会写入 `am-serve/plugin/{name}/app/view/app/` |
