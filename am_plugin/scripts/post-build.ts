/**
 * 打包后处理脚本（双入口架构）
 *
 * Vite 会将 index.html 和静态资源一起输出到 outDir（public/assets/）。
 * 同一份构建产物会被两个后端页面入口加载：
 * - 管理入口 /app/{name}/manage → 视图 manage.html（入口模式 manage）
 * - 前端入口 /app/{name}        → 视图 index.html（入口模式 front，公开可访问）
 *
 * 本脚本以构建出的 index.html 为模板生成两个视图，并在 <head> 后注入
 * window.__PLUGIN_ENTRY__ 标识（src/sdk/entry.ts 据此解析入口模式）。
 * 产物/视图落位：由前端工作区 .env 的 VITE_PLUGIN_ROOT 指向插件根（前后端解耦，
 * 前端独立放于 atrman/am_frontends/{name}，产物回写到 am-serve/plugin/{name}）。
 * 也可用 --plugin/--assets/--views 临时覆盖。
 *
 * 由 package.json 的 build 脚本自动调用：
 * "build": "vite build && vue-tsc --noEmit && tsx scripts/post-build.ts"
 */
import { readFileSync, writeFileSync, existsSync, mkdirSync, unlinkSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import { loadEnv } from 'vite'

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = resolve(__dirname, '..')

// VITE_PLUGIN_ROOT 指向插件根（相对本项目根），是产物落位的唯一依据
const env = loadEnv('production', root, '')
const envPluginRoot = env.VITE_PLUGIN_ROOT ? resolve(root, env.VITE_PLUGIN_ROOT) : null

/**
 * 可选命令行覆盖（临时指定插件）：
 *   --plugin <dir>   插件根目录（内含 manifest.json），等价于 assets=<dir>/public/assets、views=<dir>/app/view/app
 *   --assets <dir>   仅覆盖构建产物目录
 *   --views <dir>    仅覆盖视图输出目录
 * 例：tsx scripts/post-build.ts --plugin ../am-serve/plugin/am_demo
 */
function readCliOverride(name: string): string | null {
  const i = process.argv.indexOf(name)
  return i !== -1 && process.argv[i + 1] ? resolve(root, process.argv[i + 1]) : null
}
const cliPlugin = readCliOverride('--plugin')
const cliAssets = readCliOverride('--assets') ?? (cliPlugin ? resolve(cliPlugin, 'public', 'assets') : null)
const cliViews = readCliOverride('--views') ?? (cliPlugin ? resolve(cliPlugin, 'app', 'view', 'app') : null)

/** 入口标识注入脚本：sdk/entry.ts 的 resolveEntryMode 第二优先级来源 */
const entryScript = (mode: 'manage' | 'front') =>
  `<script>window.__PLUGIN_ENTRY__='${mode}'</script>`

/** 在构建 HTML 的 <head> 开头注入入口标识脚本 */
function injectEntry(html: string, mode: 'manage' | 'front'): string {
  const tag = entryScript(mode)
  if (html.includes('__PLUGIN_ENTRY__')) return html
  if (html.includes('<head>')) return html.replace('<head>', `<head>\n${tag}`)
  return `${tag}\n${html}`
}

// 1. 解析插件根与产物/视图目录（VITE_PLUGIN_ROOT 单一来源，CLI 覆盖可选）
const pluginRoot = cliPlugin ?? envPluginRoot
const assetsDir = cliAssets ?? (pluginRoot ? resolve(pluginRoot, 'public', 'assets') : null)
const viewDir = cliViews ?? (pluginRoot ? resolve(pluginRoot, 'app', 'view', 'app') : null)
if (!assetsDir || !viewDir) {
  console.error('❌ 未配置 VITE_PLUGIN_ROOT，无法确定产物落位的插件目录。')
  console.error('   请在 .env 设置 VITE_PLUGIN_ROOT（由 create-plugin.mjs 自动生成），或用 --plugin <dir> 指定插件根。')
  process.exit(1)
}

// 2. 定位构建产物并读取入口 HTML
if (!existsSync(resolve(assetsDir, 'index.html'))) {
  console.warn(`⚠️  未找到构建产物 ${resolve(assetsDir, 'index.html')}（请先执行 vite build），跳过视图生成`)
  process.exit(0)
}
const htmlPath = resolve(assetsDir, 'index.html')
const builtHtml = readFileSync(htmlPath, 'utf-8')
if (!existsSync(viewDir)) {
  mkdirSync(viewDir, { recursive: true })
  console.log(`✓ 创建视图目录：${viewDir}`)
}

const managePath = resolve(viewDir, 'manage.html')
const frontPath = resolve(viewDir, 'index.html')
writeFileSync(managePath, injectEntry(builtHtml, 'manage'), 'utf-8')
console.log(`✓ 管理入口视图 → ${managePath}`)
writeFileSync(frontPath, injectEntry(builtHtml, 'front'), 'utf-8')
console.log(`✓ 前端入口视图 → ${frontPath}`)

// 3. 删除 public/assets/ 下的 index.html（入口 HTML 已由视图承载，避免暴露目录）
unlinkSync(htmlPath)
console.log(`✓ 清理 public/assets/index.html`)

// 4. 输出打包摘要
console.log('')
console.log('═══════════════════════════════════════')
console.log('  打包完成！产物分布：')
console.log('───────────────────────────────────────')
console.log(`  静态资源 → ${assetsDir}`)
console.log(`  管理入口视图 → ${managePath}`)
console.log(`  前端入口视图 → ${frontPath}`)
console.log('═══════════════════════════════════════')
console.log('')
console.log('下一步：')
console.log('  1. manifest.json 的 entry=/app/{插件名}、manage_entry=/app/{插件名}/manage')
console.log('  2. 后端 AppController::index() 渲染 app/index，manage() 渲染 app/manage')
console.log('  3. 前端公开接口加入控制器 $noNeedLogin 白名单')
