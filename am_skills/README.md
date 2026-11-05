# Atrman Agent Skills

Atrman 官方 **Agent 技能包**：与具体 AI 编程助手解耦，任何支持 Agent Skills 约定（一个目录 + `SKILL.md`，YAML frontmatter 声明 `name` / `description`）的 agent 都能安装使用。

## 目录结构

```text
am_skill/
├── README.md
├── install.sh                # Linux / macOS / Git Bash 安装器
├── install.bat               # Windows 安装器（双击即可，纯 ASCII）
├── am_plugin_dev/            # 技能目录：每个含 SKILL.md 的子目录就是一个技能
│   └── SKILL.md              # 插件开发技能
└── am_dev_standard/
    └── SKILL.md              # 基座本体（am-serve / am-web）开发规范技能
```

- **规范源只有一份**：技能平铺在 `am_skill/<技能名>/`，新增技能直接在这层建目录。
- 安装 = 把技能目录复制到目标 agent 的技能目录；重新安装会覆盖旧副本。

## 已有技能

| 技能 | 用途 |
| --- | --- |
| `am_plugin_dev` | Atrman 插件全流程开发：脚手架、manifest、生命周期、控制器路由、前后端约定、打包排错 |
| `am_dev_standard` | 基座本体（am-serve / am-web）开发规范：分层、CRUD 约定、数据库、响应体、权限、缓存与代码审查 |

## 安装

### Claude Code

```bash
# 项目级（推荐，随仓库走，团队成员 clone 即得）
cd am_skill
./install.sh project          # Windows: install.bat project
# 复制到仓库根的 .claude/skills/

# 用户级（你本机所有项目可用）
./install.sh user             # Windows: install.bat user
# 复制到 ~/.claude/skills/（Windows: %USERPROFILE%\.claude\skills\）
```

安装后重启 / 重载 agent 即可被自动发现。

### 其他 agent

凡是支持 **Agent Skills / SKILL.md 约定**的工具（Claude Code 及兼容该约定的 IDE 插件等），把技能装进它的技能目录即可：

```bash
./install.sh /path/to/agent/skills      # Windows: install.bat D:\path\to\agent\skills
```

不支持自动加载的 agent 也有两种用法：

1. **手动复制**：把需要的技能目录（如 `am_skill/am_plugin_dev`）整个拷到该工具约定的 rules / instructions / skills 目录；
2. **直接引用**：在对话里让 agent「读 `am_skill/am_plugin_dev/SKILL.md` 后按其规范工作」——SKILL.md 本身就是一份结构化操作手册。

### 查看可装技能

```bash
./install.sh list             # Windows: install.bat list
```

## 新增一个技能

1. 在 `am_skill/` 下建目录：`<kebab-case 名称>/`（与 install 脚本同级）；
2. 在其中写 `SKILL.md`，开头必须有 frontmatter：

   ```markdown
   ---
   name: 与目录名一致
   description: 一句话说清「什么时候该用这个技能」，agent 靠它判断是否触发
   ---

   # 正文：操作规范、铁律、排错索引……
   ```

3. 需要附带脚本、模板、参考文件时，放在该技能目录下随包分发；
4. 运行安装器把新技能同步到目标 agent。

## 更新技能

改 `am_skill/<技能名>/` 下的规范源后，重新执行一次安装即可覆盖所有目标副本。
