# am-serve · 后端基座

Webman 常驻内存内核 + 扩展内核 + 系统能力。框架整体介绍、插件扩展体系与生态入口见仓库根 [README.md](../README.md)，本文只讲后端基座本身。

## 从这里去哪

| 我要做什么 | 入口 |
| --- | --- |
| 装起来跑 | [部署后端基座](https://atrman.com/docs/install/backend.html) → [运行安装向导](https://atrman.com/docs/install/wizard.html) |
| 改代码前读规范 | [`../开发规范.md`](../开发规范.md) 的「二、后端开发规范」（分层 / CRUD / 权限 / 缓存 / 异常） |
| 写插件后端 | [插件开发 · 后端](https://atrman.com/docs/plugin/backend/)，骨架模板真源在 [`stub/plugin/`](stub/plugin) |
| 开 / 关扩展机制 | [`config/plugin_extend.php`](config/plugin_extend.php)（唯一的灰度与回滚入口） |
| 查路由与响应体约定 | [API 接口规范](https://atrman.com/docs/standards/api.html) |

## 目录速查

```
am-serve/
├── app/basic/          # 扩展内核注册表与基类（Event/Service/Adapter/Middleware/Task/ChannelAuth · PluginRegistrar · Base*）
├── app/controller/     # system/ · notify/ · plugin/（App · Store · Ticket · OAuth · Widget）
├── app/service/        # PluginManager · Backup · DataScope · Identity · CoreUpgrade · Store · Upload …
├── app/model/          # ThinkORM 模型（BaseModel 统一时间戳与格式化）
├── app/middleware/     # 鉴权 · 操作日志 · 安全头 · 维护模式 · InstallGuard
├── app/{queue,process,cache,event,validate,exception,interface,view,utils}/ · functions.php
├── config/             # 全部走配置文件；路由由控制器注解注册，route.php 不设默认路由
├── install/            # 安装向导资源（install.sql · seed.sql · license.txt）
├── stub/plugin/        # 插件后端骨架模板
├── plugin/             # ⚠ 应用插件目录，非框架本体（开源镜像默认剔除）
├── public/             # 管理前端构建产物回写位置（随仓库提交）
├── manifest.json       # ⭐ 版本号与运行要求唯一真源（release.php、安装与在线升级判定都读它）
└── start.php · windows.php · Dockerfile
```

## 本地启动

```bash
cd am-serve
composer install
php windows.php              # Windows 开发
php start.php start          # Linux / macOS（生产加 -d）
```

默认监听 `:8787`，首次访问由**安装向导**完成初始化（环境检测 → 建库建表 → 写 `.env` → 建管理员），不要手动改 `.env` 或导入 SQL。

环境要求：PHP ≥ 8.4（`pdo_mysql` `redis` `openssl` `zip` `gd` `fileinfo`）、MySQL ≥ 8.0、Redis ≥ 5.0。

## 注意事项

- `.env` 不入库，键名与注释见 [`.env.example`](.env.example)；`DB_PREFIX` 大小写敏感，须与建库 SQL 一致（默认 `am_`）。
- 版本号唯一真源是 `manifest.json`：`.env` 不再写 `APP_VERSION` / `APP_RELEASE`，安装与在线升级判定都直接读清单，升级时会自动清理老实例 `.env` 里的这两个残键。
- 后端骨架一律由基座后台「创建 / 重新生成自定义插件」生成；前端框架在仓库的 `am_plugin/` 与 `am_frontends/`，不在本目录。
