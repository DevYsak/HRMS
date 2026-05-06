#!/usr/bin/env bash
# ============================================================
#  PULSE HRMS - Deploy Script
#  Run     : bash deploy.sh
#  Purpose : Push committed code to GitHub, pull on server,
#            run migrations, rebuild assets, and refresh caches
# ============================================================

set -euo pipefail

# Configuration
SERVER_USER="ysak"
SERVER_HOST="69.62.74.243"
APP_DIR="/home/ysak/app/nexc"
GIT_BRANCH="staging"
PHP_BIN="php"
COMPOSER_BIN="composer"
SITE_URL="https://nexcore.wayforweb.tech"
RUN_SEEDERS="${RUN_SEEDERS:-false}" # use RUN_SEEDERS=true bash deploy.sh

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'
BOLD='\033[1m'

step() { echo -e "\n${CYAN}${BOLD}[${1}]${NC} ${2}"; }
ok()   { echo -e "  ${GREEN}OK${NC} ${1}"; }
warn() { echo -e "  ${YELLOW}WARN${NC} ${1}"; }
fail() { echo -e "  ${RED}FAIL${NC} ${1}"; exit 1; }

echo ""
echo -e "${BOLD}==============================================${NC}"
echo -e "${BOLD}  PULSE HRMS DEPLOY${NC}"
echo -e "${BOLD}  ${SITE_URL}${NC}"
echo -e "${BOLD}==============================================${NC}"
echo ""

LOCAL_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
LOCAL_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

echo -e "  Local branch : ${YELLOW}${LOCAL_BRANCH}${NC}"
echo -e "  Local commit : ${YELLOW}${LOCAL_COMMIT}${NC}"
echo -e "  Deploy branch: ${YELLOW}${GIT_BRANCH}${NC}"

if ! git diff --quiet || ! git diff --cached --quiet; then
    fail "Working tree clean nahi hai. Pehle commit karo, phir deploy chalao."
fi

if git ls-files --others --exclude-standard | grep -q .; then
    fail "Untracked files mile hain. Unhe add/commit ya ignore karke phir deploy chalao."
fi

if [ "$LOCAL_BRANCH" != "$GIT_BRANCH" ]; then
    warn "Aap '${LOCAL_BRANCH}' par ho, deploy '${GIT_BRANCH}' branch ka hoga. Continue? (y/N)"
    read -r confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

echo ""
echo -e "  ${YELLOW}Starting deployment at $(date '+%Y-%m-%d %H:%M:%S')${NC}"

step "1/9" "Pushing committed changes to GitHub"
git push origin "$GIT_BRANCH" 2>&1 | sed 's/^/  /'
ok "Code pushed to GitHub"

step "2/9" "Connecting to server ${SERVER_HOST}"

ssh -o StrictHostKeyChecking=no \
    -o ConnectTimeout=15 \
    "${SERVER_USER}@${SERVER_HOST}" \
    APP_DIR="$APP_DIR" \
    GIT_BRANCH="$GIT_BRANCH" \
    PHP_BIN="$PHP_BIN" \
    COMPOSER_BIN="$COMPOSER_BIN" \
    RUN_SEEDERS="$RUN_SEEDERS" \
    'bash -s' <<'REMOTE'
set -euo pipefail

G='\033[0;32m'
Y='\033[1;33m'
R='\033[0;31m'
NC='\033[0m'

ok()   { echo -e "  ${G}OK${NC} ${1}"; }
fail() { echo -e "  ${R}FAIL${NC} ${1}"; exit 1; }

cd "$APP_DIR" || fail "App directory not found: $APP_DIR"

cleanup() {
    $PHP_BIN artisan up >/dev/null 2>&1 || true
}

trap cleanup EXIT

echo ""
echo "  -- Maintenance Mode ON"
$PHP_BIN artisan down --retry=5 2>/dev/null || true

echo ""
echo "  -- Pull latest code"
git fetch origin
git reset --hard "origin/${GIT_BRANCH}"
ok "Code updated to $(git rev-parse --short HEAD)"

echo ""
echo "  -- Install PHP dependencies"
$COMPOSER_BIN install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist
ok "Composer install complete"

echo ""
echo "  -- Build frontend assets"
if [ -f "package.json" ]; then
    npm ci --prefer-offline || npm install
    npm run build
    ok "Frontend build complete"
else
    echo "  package.json not found, skipping npm build"
fi

echo ""
echo "  -- Run database migrations"
$PHP_BIN artisan migrate --force --no-interaction
ok "Migrations applied"

if [ "$RUN_SEEDERS" = "true" ]; then
    echo ""
    echo "  -- Run database seeders"
    $PHP_BIN artisan db:seed --force --no-interaction
    ok "Seeders executed"
fi

echo ""
echo "  -- Storage and permissions"
$PHP_BIN artisan storage:link 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
ok "Storage linked and permissions updated"

echo ""
echo "  -- Rebuild Laravel caches"
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache
$PHP_BIN artisan optimize
ok "Caches rebuilt"

echo ""
echo "  -- Restart queue workers"
$PHP_BIN artisan queue:restart
ok "Queue workers restarted"

echo ""
echo "  -- Maintenance Mode OFF"
$PHP_BIN artisan up
ok "Site is live"
REMOTE

step "9/9" "Deployment complete"
ok "Deployment finished successfully"
echo ""
echo -e "  ${GREEN}${BOLD}Live at: ${SITE_URL}${NC}"
echo -e "  ${YELLOW}Completed at $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo ""
