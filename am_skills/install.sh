#!/usr/bin/env bash
#
# Atrman Agent Skills installer (Linux / macOS / Git Bash on Windows)
#
# Each skill is a self-contained directory: skills/<name>/SKILL.md
# The Agent Skills convention (SKILL.md + YAML frontmatter) is understood by
# Claude Code natively; any other agent that supports the same convention can
# point at or receive a copy of the same directory.
#
# Usage:
#   ./install.sh              Same as "project"
#   ./install.sh project      Install into ./.claude/skills  (this repo)
#   ./install.sh user         Install into ~/.claude/skills   (all projects)
#   ./install.sh <dir>        Install into any agent skills directory
#   ./install.sh list         List available skills
#
# Re-running the installer overwrites previously installed copies.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# 技能平铺在本目录：每个含 SKILL.md 的子目录就是一个技能
SKILLS_SRC="$SCRIPT_DIR"

usage() {
  sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
}

list_skills() {
  local count=0
  for d in "$SKILLS_SRC"/*/; do
    [ -d "$d" ] || continue
    [ -f "$d/SKILL.md" ] || { echo "  (skip $(basename "$d"): SKILL.md missing)" >&2; continue; }
    echo "  - $(basename "$d")"
    count=$((count + 1))
  done
  [ "$count" -gt 0 ] || echo "  (no skills found in $SKILLS_SRC)"
}

install_into() {
  local dest="$1"
  if [ ! -d "$SKILLS_SRC" ]; then
    echo "ERROR: skills source not found: $SKILLS_SRC" >&2
    exit 1
  fi

  mkdir -p "$dest"
  echo "Installing skills into: $dest"

  local installed=0
  for d in "$SKILLS_SRC"/*/; do
    [ -d "$d" ] || continue
    local name
    name="$(basename "$d")"
    if [ ! -f "$d/SKILL.md" ]; then
      echo "  skip $name (SKILL.md missing)"
      continue
    fi
    rm -rf "$dest/$name"
    cp -R "$d" "$dest/$name"
    echo "  + $name"
    installed=$((installed + 1))
  done

  echo "Done. $installed skill(s) installed."
  echo "Restart / reload your agent so it picks up the new skills."
}

case "${1:-project}" in
  -h|--help|help)
    usage
    ;;
  list)
    echo "Available skills in $SKILLS_SRC:"
    list_skills
    ;;
  project)
    install_into "$SCRIPT_DIR/../.claude/skills"
    ;;
  user)
    install_into "$HOME/.claude/skills"
    ;;
  *)
    install_into "$1"
    ;;
esac
