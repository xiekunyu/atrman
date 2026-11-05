#!/usr/bin/env bash
# Atrman 插件前端「脚手架装配」启动器（Linux / macOS / Git Bash）
# 用法：bash 创建插件脚手架.sh am_demo；或直接 bash 创建插件脚手架.sh 按提示输入插件名

set -e
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAME="${1:-}"

if [ -z "$NAME" ]; then
  read -rp "请输入插件名（如 am_demo，需 am_ 前缀且基座已建后端骨架）: " NAME
fi

if [ -z "$NAME" ]; then
  echo "❌ 未输入插件名，已取消。"
  exit 1
fi

node "$DIR/am_frontends/create-plugin.mjs" "$NAME"

echo ""
echo "装配完成后，请进入前端工作区继续开发："
echo "  cd am_frontends/$NAME && pnpm install && pnpm dev"
