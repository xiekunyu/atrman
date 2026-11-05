#!/usr/bin/env node

/**
 * Atrman 插件「前端」装配脚本
 *
 * 用法（本脚本位于 am_frontends 目录）：
 *   node create-plugin.mjs am_my_plugin
 *   或直接双击 atrman 根目录的启动器「创建插件脚手架.bat」（Windows）/「创建插件脚手架.sh」（Linux/macOS），
 *   启动器会调起本脚本（也可带参：创建插件脚手架.bat am_my_plugin）
 *   （.mjs 原生按 ES Module 解析，无需 package.json；Node >= 20.19）
 *
 * 职责边界（双入口架构 · 前后端解耦 · 单一真源约定）：
 *   - 后端骨架（manifest.json / Plugin.php / config / 控制器 / 视图）全部由【基座】的
 *     「创建 / 重新生成自定义插件」功能生成，模板真源在 am-serve/stub/plugin/*.stub。
 *   - 本脚本【不生成任何 PHP】，只负责把前端框架真源 atrman/am_plugin 装配到独立前端工作区
 *     atrman/am_frontends/{name}/（不内嵌到插件目录，避免插件包冗余）。
 *
 * 产物落位机制：前端工作区 .env 会写入 VITE_PLUGIN_ROOT（指向插件根的相对路径），
 *   vite 的 outDir 与 post-build 都据此把静态资源/双入口视图写回插件目录。
 *
 * 前置条件：
 *   先在基座后台创建同名插件（生成 am-serve/plugin/{name}/ 后端骨架与 manifest.json），
 *   再运行本脚本装配前端。
 *
 * 产出目录结构：
 *   am-serve/plugin/{name}/        ← 后端（只有骨架 + 构建产物，无前端源码）
 *   ├── manifest.json / app / config / public   ← 基座生成
 *   └── public/assets + app/view/app            ← 前端构建产物回写处
 *
 *   am_frontends/{name}/           ← 本脚本装配（Vue 前端源码 + 构建脚本，独立文件夹）
 */

import fs from 'node:fs'
import path from 'node:path'

const __dirname = import.meta.dirname

const pluginName = process.argv[2]

if (!pluginName) {
  console.error('❌ 请指定插件名称')
  console.error('   用法: node create-plugin.mjs am_my_plugin   （在 am_frontends 目录下，或双击 atrman 根目录的创建插件脚手架.bat / .sh）')
  process.exit(1)
}

if (!/^am_[a-z][a-z0-9_]*$/.test(pluginName)) {
  console.error('❌ 插件名称格式不正确，建议：am_ 前缀 + 小写字母数字下划线')
  console.error('   示例: am_demo, am_todo, am_im')
  process.exit(1)
}

// 路径基准（本脚本位于 atrman/am_frontends）：
//   前端框架真源：atrman/am_plugin            （从它拷贝装配）
//   后端插件根：  atrman/am-serve/plugin/{name}（由基座生成，本脚本不碰其 PHP）
//   前端工作区：  atrman/am_frontends/{name}   （本脚本所在目录下的子文件夹）
// 前后端通过前端 .env 里的 VITE_PLUGIN_ROOT 关联：构建产物写回插件目录，源码不进插件目录。
const atrmanRoot = path.resolve(__dirname, '..')
const scaffoldSrc = path.join(atrmanRoot, 'am_plugin')
const pluginDir = path.join(atrmanRoot, 'am-serve', 'plugin', pluginName)
const frontendDir = path.join(__dirname, pluginName)

// 从前端工作区指向插件根的相对路径（写入 VITE_PLUGIN_ROOT，vite/post-build 据此落位产物）
const pluginRootRel = path.relative(frontendDir, pluginDir).split(path.sep).join('/')

if (!fs.existsSync(path.join(scaffoldSrc, 'package.json'))) {
  console.error(`❌ 未找到前端框架真源：${scaffoldSrc}`)
  console.error('   本脚本需与 am_plugin 同级放置于 atrman 目录下（从 am_plugin 拷贝前端框架）。')
  process.exit(1)
}

if (!fs.existsSync(path.join(pluginDir, 'manifest.json'))) {
  console.error(`❌ 未找到插件后端：${path.join(pluginDir, 'manifest.json')}`)
  console.error('   后端骨架（控制器 / 路由 / 视图 / manifest）由基座生成，本脚本不再产出 PHP。')
  console.error('   请先在基座后台「创建自定义插件」，或对已有插件点「重新生成模板」，再运行本脚本装配前端。')
  process.exit(1)
}

if (fs.existsSync(frontendDir)) {
  console.error(`❌ 前端工作区已存在: ${frontendDir}`)
  console.error('   如需重新装配，请先删除该目录。')
  process.exit(1)
}

fs.mkdirSync(frontendDir, { recursive: true })

console.log(`\n🚀 为插件装配前端: ${pluginName}`)
console.log(`   框架真源            : ${scaffoldSrc}`)
console.log(`   后端目录（产物落位处）: ${pluginDir}`)
console.log(`   前端工作区（源码）    : ${frontendDir}`)
console.log(`   VITE_PLUGIN_ROOT      : ${pluginRootRel}\n`)

// ──── 复制前端框架源码 ────

const scaffoldDirs = ['src', 'scripts', 'public']

scaffoldDirs.forEach((dir) => {
  const src = path.join(scaffoldSrc, dir)
  if (fs.existsSync(src)) {
    copyDirSync(src, path.join(frontendDir, dir))
  }
})

// 复制配置文件
const configFiles = [
  'index.html',
  'tsconfig.json',
  'vite.config.ts',
  'eslint.config.mjs',
  '.prettierrc',
  '.prettierignore',
  '.stylelintrc.cjs',
  '.stylelintignore',
  'commitlint.config.cjs',
  '.gitignore',
  'pnpm-workspace.yaml',
  // 锁定依赖版本：不带 lockfile 时插件目录 pnpm install 会按 ^ 范围解析到更新的小版本
  // （如 element-plus 2.11 → 2.14），导致类型定义变化后 vue-tsc 校验失败等漂移问题
  'pnpm-lock.yaml'
]

configFiles.forEach((file) => {
  const src = path.join(scaffoldSrc, file)
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, path.join(frontendDir, file))
  }
})

// 复制 package.json 并修改名称
const pkgPath = path.join(scaffoldSrc, 'package.json')
if (fs.existsSync(pkgPath)) {
  const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
  pkg.name = `${pluginName}-frontend`
  pkg.description = `${pluginName} 插件前端`
  fs.writeFileSync(path.join(frontendDir, 'package.json'), JSON.stringify(pkg, null, 2), 'utf-8')
}

// ──── 前端环境变量（.env / .env.development / .env.production）────

const envContent = `# 插件标识（必须与 manifest.json 的 name 一致）
VITE_PLUGIN_NAME=${pluginName}
# 插件根目录（相对本前端工作区），构建产物与双入口视图据此回写到插件目录；请勿删除。
VITE_PLUGIN_ROOT=${pluginRootRel}
# 应用部署基础路径（静态资源 URL 前缀）
VITE_BASE_URL=/app/${pluginName}/assets/
# 开发服务器端口
VITE_PORT=3007
# API 代理目标地址（与基座后端一致；后端监听 http 就填 http，监听 https 就填 https）
VITE_API_PROXY_URL=http://localhost:8787
# API 路径前缀
VITE_API_URL=/api
# 会员中心（am_member）前台入口；登录/注册/个人中心统一跳这里，插件不自建登录页
VITE_MEMBER_ENTRY=/app/am_member
`

const envDevContent = `# 插件开发环境配置
VITE_PLUGIN_NAME=${pluginName}
# 插件根目录（相对本前端工作区）
VITE_PLUGIN_ROOT=${pluginRootRel}
# 应用部署基础路径（开发模式直接走根路径）
VITE_BASE_URL=/
# 开发服务器端口
VITE_PORT=3007
# API 代理目标地址
VITE_API_PROXY_URL=http://localhost:8787
# API 路径前缀
VITE_API_URL=/api
# 会员中心入口：跨端口直连时 localStorage 不共享、无法体验完整登录闭环（仅验证跳转 URL 生成）；
# 完整闭环请用同域 host/反代联调（默认相对路径 /app/am_member 即同域形态）
VITE_MEMBER_ENTRY=/app/am_member
`

const envProdContent = `# 插件生产环境配置
VITE_PLUGIN_NAME=${pluginName}
# 插件根目录（相对本前端工作区）
VITE_PLUGIN_ROOT=${pluginRootRel}
# 应用部署基础路径（静态资源 URL 前缀）
VITE_BASE_URL=/app/${pluginName}/assets/
# API 路径前缀
VITE_API_URL=/api
# 会员中心前台入口（同域部署，勿改成跨域地址：登录态依赖同域 localStorage 契约）
VITE_MEMBER_ENTRY=/app/am_member
`

fs.writeFileSync(path.join(frontendDir, '.env'), envContent, 'utf-8')
fs.writeFileSync(path.join(frontendDir, '.env.development'), envDevContent, 'utf-8')
fs.writeFileSync(path.join(frontendDir, '.env.production'), envProdContent, 'utf-8')

// ──── 安装提示 ────

console.log('✅ 插件前端装配完成！\n')
console.log('目录结构（前后端解耦）：')
console.log('  后端插件目录（源码不进这里，只有骨架 + 构建产物）')
console.log(`  ${pluginDir}/`)
console.log(`  ├── manifest.json / Plugin.php / config/     （基座生成）`)
console.log(`  ├── app/controller/        （基座生成：双入口控制器）`)
console.log(`  ├── app/view/app/          （基座占位视图 → pnpm build 覆盖为双入口视图）`)
console.log(`  └── public/assets/         （前端构建产物落地处）`)
console.log('')
console.log('  前端工作区（本脚本装配，独立文件夹，可单独纳入版本管理）')
console.log(`  ${frontendDir}/`)
console.log('  ├── src/  scripts/  public/')
console.log('  ├── package.json  vite.config.ts  tsconfig.json  .env*')
console.log('  └── pnpm-lock.yaml')
console.log('')
console.log('下一步：')
console.log(`  cd ${path.relative(process.cwd(), frontendDir)}`)
console.log('  pnpm install')
console.log('  pnpm dev            # 直接访问 https://localhost:3007/ 默认进前台（#/index/home），后台管理预览显式走 #/manage/home')
console.log('  pnpm build          # 一次构建：产物写入插件 public/assets，并生成 manage/index 双入口视图到 app/view/app')
console.log('')
console.log('提示：入口约定为 /app/' + pluginName + '（前台公开）与 /app/' + pluginName + '/manage（管理）。')
console.log('     前后端通过前端 .env 的 VITE_PLUGIN_ROOT=' + pluginRootRel + ' 关联，请勿删除该行。')

// ──── 工具函数 ────

function copyDirSync(src, dest) {
  if (!fs.existsSync(dest)) {
    fs.mkdirSync(dest, { recursive: true })
  }
  const entries = fs.readdirSync(src, { withFileTypes: true })
  for (const entry of entries) {
    const srcPath = path.join(src, entry.name)
    const destPath = path.join(dest, entry.name)
    if (entry.isDirectory()) {
      copyDirSync(srcPath, destPath)
    } else {
      fs.copyFileSync(srcPath, destPath)
    }
  }
}
