import fs from 'fs/promises'
import path from 'path'

const root = process.cwd()
const distDir = path.resolve(root, 'dist')
const publicDir = path.resolve(root, '../am-serve/public')
const assetsDir = path.resolve(publicDir, 'assets')

async function postBuild() {
  try {
    await fs.rm(assetsDir, { recursive: true, force: true })
    console.log('  已删除 am-serve/public/assets')
  } catch {
    console.log('  am-serve/public/assets 不存在，跳过删除')
  }

  try {
    await fs.cp(distDir, publicDir, { recursive: true, force: true })
    console.log('  已将 dist 复制到 am-serve/public')
  } catch (e) {
    console.error('  复制失败:', e)
    process.exit(1)
  }

  // 已安装且配置了自定义后台入口时，将复制过来的 index.html 同步改名，
  // 避免每次构建后默认入口"复活"
  await applyEntryFile()
}

/**
 * 读取 am-serve/.env 的 APP_ENTRY_FILE，非默认时把 public/index.html 改名为目标文件名
 */
async function applyEntryFile() {
  let entryFile = ''
  try {
    const envContent = await fs.readFile(path.resolve(publicDir, '../.env'), 'utf8')
    const match = envContent.match(/^APP_ENTRY_FILE\s*=\s*"?([^"\r\n]+?)"?\s*$/m)
    entryFile = match?.[1]?.trim() ?? ''
  } catch {
    return // .env 不存在 = 未安装，保持默认
  }

  if (!entryFile || entryFile === 'index.html') return

  const src = path.resolve(publicDir, 'index.html')
  const dst = path.resolve(publicDir, entryFile)
  try {
    await fs.rm(dst, { force: true })
    await fs.rename(src, dst)
    console.log(`  已按 APP_ENTRY_FILE 将入口改名为 ${entryFile}`)
  } catch (e) {
    console.warn('  入口文件改名失败（不影响构建产物）:', e)
  }
}

postBuild()
