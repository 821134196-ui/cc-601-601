#!/usr/bin/env bash
# 社区共享雨伞系统 · 一键启动
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
RUNTIME="$ROOT/.runtime"
PHP="$RUNTIME/php"
PHP_VERSION="8.4.23"

echo "☂ 社区共享雨伞归还点容量管理系统"

# 1. PHP 运行时（优先使用系统 PHP，否则下载静态编译版）
if command -v php >/dev/null 2>&1 && php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' 2>/dev/null; then
  PHP="$(command -v php)"
  echo "✓ 使用系统 PHP ($(php -r 'echo PHP_VERSION;'))"
elif [ -x "$PHP" ]; then
  echo "✓ 使用本地 PHP 运行时"
else
  echo "… 下载静态 PHP $PHP_VERSION（含 SQLite 扩展）"
  mkdir -p "$RUNTIME"
  curl -sL -o "$RUNTIME/php.tar.gz" "https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
  tar xzf "$RUNTIME/php.tar.gz" -C "$RUNTIME"
  rm -f "$RUNTIME/php.tar.gz"
fi

# 2. 后端依赖
cd "$ROOT/server"
if [ ! -d vendor ]; then
  echo "… 安装后端依赖（Composer）"
  [ -f "$RUNTIME/composer" ] || curl -sL -o "$RUNTIME/composer" https://getcomposer.org/download/latest-stable/composer.phar
  "$PHP" "$RUNTIME/composer" install --no-interaction --prefer-dist --quiet
fi
[ -f .env ] || cp .env.example .env
if ! grep -q '^APP_KEY=base64:.' .env; then
  "$PHP" artisan key:generate --force --quiet
fi
echo "✓ 后端就绪"

# 3. 前端构建（有 npm 且未构建过时）
if [ ! -f public/app/index.html ]; then
  if command -v npm >/dev/null 2>&1; then
    echo "… 构建前端（React）"
    cd "$ROOT/web"
    npm install --no-audit --no-fund --silent
    npm run build --silent
    cd "$ROOT/server"
    echo "✓ 前端构建完成"
  else
    echo "⚠ 未找到 npm，跳过前端构建（API 仍可用，页面无法访问）"
  fi
else
  echo "✓ 前端已构建"
fi

# 4. 数据库迁移与初始数据
touch database/database.sqlite
"$PHP" artisan migrate --force --quiet
STATIONS=$("$PHP" -r '$db=new PDO("sqlite:'"$ROOT"'/server/database/database.sqlite");echo $db->query("SELECT COUNT(*) FROM stations")->fetchColumn();' 2>/dev/null || echo 0)
if [ "$STATIONS" = "0" ]; then
  "$PHP" artisan db:seed --force --quiet
  echo "✓ 数据库已初始化（3 个站点示例数据）"
else
  echo "✓ 数据库已存在（$STATIONS 个站点）"
fi

# 5. 启动服务
PORT="${PORT:-8000}"
echo ""
echo "──────────────────────────────────────────────"
echo "  页面入口   http://127.0.0.1:$PORT"
echo "  API       http://127.0.0.1:$PORT/api/stations"
echo "  运行测试   cd server && ../../.runtime/php artisan test"
echo "──────────────────────────────────────────────"
exec "$PHP" artisan serve --port="$PORT"
