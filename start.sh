#!/usr/bin/env bash
# 社区共享雨伞系统 —— 一键启动
# 用法: ./start.sh [--fresh]
#   --fresh  清空数据库并重新迁移+播种
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# PHP 运行时:优先使用仓库自带的静态 PHP(.runtime/),否则回退到系统 PHP
if [ -x "$ROOT/.runtime/php" ]; then
  PHP="$ROOT/.runtime/php"
  COMPOSER="$ROOT/.runtime/composer"
else
  PHP="$(command -v php || true)"
  COMPOSER="$(command -v composer || true)"
  [ -n "$PHP" ] && [ -n "$COMPOSER" ] || { echo "错误: 需要 PHP 8.2+ 与 Composer"; exit 1; }
fi

cd "$ROOT/server"
[ -d vendor ] || "$PHP" "$COMPOSER" install --no-interaction --no-progress --optimize-autoloader
[ -f .env ] || cp .env.example .env
[ -f database/database.sqlite ] || touch database/database.sqlite
grep -q '^APP_KEY=base64:' .env || "$PHP" artisan key:generate --force --no-interaction

if [ "${1:-}" = "--fresh" ]; then
  "$PHP" artisan migrate:fresh --force --no-interaction
else
  "$PHP" artisan migrate --force --no-interaction
fi
"$PHP" artisan db:seed --class=DemoSeeder --force --no-interaction

cd "$ROOT/web"
[ -d node_modules ] || npm install --no-fund --no-audit
cd "$ROOT"

echo ""
echo "  ☂️  社区共享雨伞系统"
echo "  前端(居民/管理):  http://127.0.0.1:5173"
echo "  后端 API:          http://127.0.0.1:8000/api"
echo "  柜机模拟器:        已启动(日志见控制台)"
echo ""
echo "  Ctrl+C 停止全部进程"
echo ""

# 内建服务器默认单线程;多 worker 让柜机模拟器的轮询不阻塞页面请求
# setsid 使每个进程自成进程组,退出时能整组回收(包括 npm/vite 的子孙进程)
PHP_CLI_SERVER_WORKERS=6 setsid "$PHP" "$ROOT/server/artisan" serve --no-reload --host=127.0.0.1 --port=8000 &
API_PID=$!
setsid "$PHP" "$ROOT/server/artisan" cabinet:run &
SIM_PID=$!
(cd "$ROOT/web" && exec setsid npm run dev -- --host 127.0.0.1 --port 5173) &
WEB_PID=$!

cleanup() {
  kill -- -"$API_PID" -"$SIM_PID" -"$WEB_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM
wait
