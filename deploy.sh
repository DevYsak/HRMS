#!/usr/bin/env bash
# ============================================================
#  PULSE HRMS — Deploy Script
#  Server  : nexcore.wayforweb.tech
#  Run     : bash deploy.sh
#  Prereq  : bash setup-deploy-key.sh (sirf pehli baar)
# ============================================================

set -euo pipefail

# ── CONFIGURATION ────────────────────────────────────────────
SERVER_USER="ysak"
SERVER_HOST="69.62.74.243"
APP_DIR="/home/ysak/app/nexc"
GIT_BRANCH="staging"
PHP_BIN="php"          # server par php ka command (php / php8.3)
COMPOSER_BIN="composer" # composer ka command
SITE_URL="https://nexcore.wayforweb.tech"
# ─────────────────────────────────────────────────────────────

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m' # No Color
BOLD='\033[1m'

step() { echo -e "\n${CYAN}${BOLD}[${1}]${NC} ${2}"; }
ok()   { echo -e "  ${GREEN}✅ ${1}${NC}"; }
warn() { echo -e "  ${YELLOW}⚠️  ${1}${NC}"; }
fail() { echo -e "  ${RED}❌ ${1}${NC}"; exit 1; }

# ─────────────────────────────────────────────────────────────

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║   🚀  PULSE HRMS — Deploying to Live         ║${NC}"
echo -e "${BOLD}║   ${SITE_URL}   ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════╝${NC}"
echo ""

# Local: current git branch check
LOCAL_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
LOCAL_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

echo -e "  Local branch : ${YELLOW}${LOCAL_BRANCH}${NC}"
echo -e "  Local commit : ${YELLOW}${LOCAL_COMMIT}${NC}"
echo -e "  Deploying to : ${YELLOW}${GIT_BRANCH}${NC} branch on server"

if [ "$LOCAL_BRANCH" != "$GIT_BRANCH" ]; then
    warn "You are on '${LOCAL_BRANCH}' but deploying '${GIT_BRANCH}'. Continue? (y/N)"
    read -r confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 0; }
fi

echo ""
echo -e "  ${YELLOW}Starting deployment at $(date '+%Y-%m-%d %H:%M:%S')${NC}"

# ─────────────────────────────────────────────────────────────
# Push local commits to GitHub first
# ─────────────────────────────────────────────────────────────
step "1/8" "Pushing local commits to GitHub..."
git push origin "$GIT_BRANCH" 2>&1 | sed 's/^/  /'
ok "Code pushed to GitHub"

# ─────────────────────────────────────────────────────────────
# SSH into server and run all deployment commands
# ─────────────────────────────────────────────────────────────
step "2/8" "Connecting to server ${SERVER_HOST}..."

ssh -o StrictHostKeyChecking=no \
    -o ConnectTimeout=15 \
    "${SERVER_USER}@${SERVER_HOST}" \
    APP_DIR="$APP_DIR" \
    GIT_BRANCH="$GIT_BRANCH" \
    PHP_BIN="$PHP_BIN" \
    COMPOSER_BIN="$COMPOSER_BIN" \
    'bash -s' << 'REMOTE'

set -e

# Colors on remote
G='\033[0;32m'  Y='\033[1;33m'  R='\033[0;31m'  NC='\033[0m'
ok()   { echo -e "  ${G}✅ ${1}${NC}"; }
fail() { echo -e "  ${R}❌ ${1}${NC}"; exit 1; }

cd "$APP_DIR" || fail "App directory not found: $APP_DIR"

echo ""
echo "  ── Pulling latest code ─────────────────────"
git fetch origin
git reset --hard "origin/${GIT_BRANCH}"
ok "Code updated to latest $(git rev-parse --short HEAD)"

echo ""
echo "  ── PHP Dependencies ────────────────────────"
$COMPOSER_BIN install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --quiet
ok "Composer packages installed"

echo ""
echo "  ── Frontend Assets ─────────────────────────"
if [ -f "package.json" ]; then
    npm ci --prefer-offline --silent 2>/dev/null || npm install --silent
    npm run build --silent
    ok "Frontend build complete"
else
    echo "  (no package.json — skipping npm build)"
fi

echo ""
echo "  ── Maintenance Mode ON ─────────────────────"
$PHP_BIN artisan down --retry=5 2>/dev/null || true

echo ""
echo "  ── Database Migrations ─────────────────────"
$PHP_BIN artisan migrate --force --no-interaction
ok "Migrations applied"

echo ""
echo "  ── Storage & Permissions ───────────────────"
$PHP_BIN artisan storage:link 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
ok "Storage linked & permissions set"

echo ""
echo "  ── Cache Rebuild ───────────────────────────"
$PHP_BIN artisan config:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:clear
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:clear
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:clear
$PHP_BIN artisan event:cache
$PHP_BIN artisan optimize
ok "All caches rebuilt"

echo ""
echo "  ── Queue Workers ───────────────────────────"
$PHP_BIN artisan queue:restart
ok "Queue workers restarted"

echo ""
echo "  ── Maintenance Mode OFF ────────────────────"
$PHP_BIN artisan up
ok "Site is live!"

echo ""
REMOTE

# ─────────────────────────────────────────────────────────────
ok "Deployment finished successfully!"
echo ""
echo -e "  ${GREEN}${BOLD}🌐 Live at: ${SITE_URL}${NC}"
echo -e "  ${YELLOW}   $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo ""
