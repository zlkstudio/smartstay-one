#!/usr/bin/env bash
# SmartStay ONE — deploy script. Copy to ~/deploy-one.sh on the server (chmod +x).
#
#   ./deploy-one.sh staging [branch]   → ~/one-staging.smartstay.ro   (default branch: main)
#   ./deploy-one.sh production         → ~/one.smartstay.ro (if a staging site exists, only the commit validated there)
#   ./deploy-one.sh rollback <staging|production>   restore the previous code (configs/storage untouched)
#   ./deploy-one.sh status
#
# Configs and storage/ live in each target and are NEVER overwritten or deleted:
#   rsync --delete skips excluded paths, and there is no folder swap (mv) that could drop them.

set -euo pipefail

REPO_URL="git@github-one:zlkstudio/smartstay-one.git"
SOURCE="$HOME/source/smartstay-one"
BACKUPS="$HOME/backups/one"
KEEP_BACKUPS=10

declare -A TARGET_DIR=(
  [staging]="$HOME/one-staging.smartstay.ro"
  [production]="$HOME/one.smartstay.ro"
)
declare -A TARGET_URL=(
  [staging]="https://one-staging.smartstay.ro"
  [production]="https://one.smartstay.ro"
)

# Every server-only file. Adding a config with credentials? Add it here — nothing else to change.
PROTECTED_CONFIGS=(
  config/app.php
  config/database-one.php
  config/database-cleaning.php
  config/database-inventory.php
  config/database-reservations.php
  config/previo.php
  config/nuki.php
  config/checkin-sync.php
)
# Required before the first deploy. The rest are optional until their stage.
REQUIRED_CONFIGS=(config/app.php config/database-one.php)

EXCLUDES=(--exclude=/.git/ --exclude=/deploy/ --exclude=/storage/ --exclude=/.deployed --exclude=.DS_Store
          --exclude=/public/.well-known/ --exclude=/cgi-bin/)   # cPanel/AutoSSL files, never ours
for f in "${PROTECTED_CONFIGS[@]}"; do EXCLUDES+=("--exclude=/$f"); done

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
info()  { printf '\033[36m→\033[0m %s\n' "$*"; }
die()   { red "✖ $*"; exit 1; }

php_bin() { command -v php 2>/dev/null || echo /usr/local/bin/php; }

sync_source() {
  local branch="$1"
  if [ ! -d "$SOURCE/.git" ]; then
    info "Clonez $REPO_URL"
    mkdir -p "$(dirname "$SOURCE")"
    git clone "$REPO_URL" "$SOURCE"
  fi
  info "Aduc origin/$branch"
  git -C "$SOURCE" fetch --prune origin
  git -C "$SOURCE" rev-parse --verify --quiet "origin/$branch" >/dev/null || die "Branch-ul origin/$branch nu există. Ai dat git push?"
  git -C "$SOURCE" checkout -q -B "$branch" "origin/$branch"
  git -C "$SOURCE" reset -q --hard "origin/$branch"
  info "Commit: $(git -C "$SOURCE" log --oneline -1)"
}

check_configs() {
  local dir="$1" missing=0
  for f in "${REQUIRED_CONFIGS[@]}"; do
    [ -f "$dir/$f" ] || { red "  lipsește $dir/$f"; missing=1; }
  done
  [ "$missing" = 0 ] || die "Creează config-urile de mai sus (din config/*.example.php) și rulează din nou."
  # base_url of staging must never point at production and vice versa.
  local name="$2" url
  url=$(grep -oE "'base_url'\s*=>\s*'[^']+'" "$dir/config/app.php" | sed -E "s/.*'(https?:[^']+)'/\1/")
  [ "$url" = "${TARGET_URL[$name]}" ] || die "config/app.php → base_url este '$url', aștept '${TARGET_URL[$name]}'."
}

backup() {
  local name="$1" dir="$2"
  [ -f "$dir/public/index.php" ] || return 0
  mkdir -p "$BACKUPS"
  local file="$BACKUPS/$name-$(date +%Y%m%d-%H%M%S).tar.gz"
  # Code only: configs and storage stay in place and are not part of a rollback.
  tar -czf "$file" -C "$dir" --exclude=./config/app.php --exclude='./config/database-*.php' \
      --exclude=./config/previo.php --exclude=./config/nuki.php --exclude=./config/checkin-sync.php --exclude=./storage .
  info "Backup: $file"
  ls -1t "$BACKUPS/$name-"*.tar.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
}

post_deploy() {
  local name="$1" dir="$2" commit="$3"
  chmod 750 "$dir/storage" "$dir/storage/logs" 2>/dev/null || true
  for f in "${PROTECTED_CONFIGS[@]}"; do [ -f "$dir/$f" ] && chmod 600 "$dir/$f"; done
  echo "$commit" > "$dir/.deployed"

  info "Migrări"
  (cd "$dir" && "$(php_bin)" bin/migrate.php) || die "Migrarea a eșuat — codul e deja copiat. Verifică eroarea, apoi: ./deploy-one.sh rollback $name"

  info "Ping ${TARGET_URL[$name]}/api/ping"
  if curl -fsS --max-time 10 "${TARGET_URL[$name]}/api/ping" | grep -q '"ok":true'; then
    green "✔ $name live: ${TARGET_URL[$name]}  ($commit)"
  else
    red "⚠ Ping eșuat. Verifică $dir/storage/logs/app.log — rollback: ./deploy-one.sh rollback $name"
  fi
  (cd "$dir" && "$(php_bin)" bin/doctor.php) || true
}

deploy_to() {
  local name="$1" dir="${TARGET_DIR[$1]}"
  mkdir -p "$dir/storage/logs" "$dir/config"
  check_configs "$dir" "$name"
  backup "$name" "$dir"
  local commit; commit=$(git -C "$SOURCE" rev-parse HEAD)
  info "rsync → $dir"
  rsync -a --delete "${EXCLUDES[@]}" "$SOURCE/" "$dir/"
  post_deploy "$name" "$dir" "$commit"
}

cmd_staging() {
  local branch="${1:-main}"
  sync_source "$branch"
  deploy_to staging
}

cmd_production() {
  sync_source main
  local head staged
  head=$(git -C "$SOURCE" rev-parse HEAD)
  staged=$(cat "${TARGET_DIR[staging]}/.deployed" 2>/dev/null || echo "")
  # Gate only when a staging site exists. Production-only setup: no gate.
  if [ -d "${TARGET_DIR[staging]}" ] && [ "$head" != "$staged" ] && [ "${FORCE:-0}" != "1" ]; then
    red "origin/main = ${head:0:7}, dar pe staging e ${staged:0:7}."
    die "Rulează întâi: ./deploy-one.sh staging  (sau FORCE=1 dacă știi ce faci)."
  fi
  deploy_to production
}

cmd_rollback() {
  local name="${1:-}"
  [ -n "${TARGET_DIR[$name]:-}" ] || die "Folosire: ./deploy-one.sh rollback <staging|production>"
  local dir="${TARGET_DIR[$name]}" latest
  latest=$(ls -1t "$BACKUPS/$name-"*.tar.gz 2>/dev/null | head -1) || true
  [ -n "$latest" ] || die "Nu există backup pentru $name."
  info "Restaurez $latest"
  local tmp; tmp=$(mktemp -d)
  tar -xzf "$latest" -C "$tmp"
  rsync -a --delete "${EXCLUDES[@]}" "$tmp/" "$dir/"
  cp "$tmp/.deployed" "$dir/.deployed" 2>/dev/null || true
  rm -rf "$tmp" "$latest"   # consumed: the next rollback goes one step further back
  green "✔ $name readus la $(cat "$dir/.deployed" 2>/dev/null | cut -c1-7)"
}

cmd_status() {
  git -C "$SOURCE" fetch -q origin 2>/dev/null || true
  echo "origin/main: $(git -C "$SOURCE" log --oneline -1 origin/main 2>/dev/null || echo '—')"
  for name in staging production; do
    local dir="${TARGET_DIR[$name]}" commit
    commit=$(cat "$dir/.deployed" 2>/dev/null || echo "")
    printf '%-11s %s  %s  ' "$name" "${commit:0:7}" "$dir"
    if curl -fsS --max-time 5 "${TARGET_URL[$name]}/api/ping" 2>/dev/null | grep -q '"ok":true'; then green "up"; else red "down"; fi
  done
  echo "backups: $(ls -1 "$BACKUPS" 2>/dev/null | wc -l) în $BACKUPS"
}

case "${1:-}" in
  staging)    cmd_staging "${2:-main}" ;;
  production) cmd_production ;;
  rollback)   cmd_rollback "${2:-}" ;;
  status)     cmd_status ;;
  *) sed -n '2,10p' "$0"; exit 1 ;;
esac
