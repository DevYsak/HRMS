# ============================================================
#  PULSE HRMS — Deploy Script (PowerShell)
#  Run: .\deploy.ps1
#  Server: nexcore.wayforweb.tech
# ============================================================

$SERVER_USER = "ysak"
$SERVER_HOST = "69.62.74.243"
$APP_DIR     = "/home/ysak/app/nexc"
$GIT_BRANCH  = "staging"
$SITE_URL    = "https://nexcore.wayforweb.tech"

Write-Host ""
Write-Host "╔══════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║   PULSE HRMS — Deploying to Live             ║" -ForegroundColor Cyan
Write-Host "║   $SITE_URL    ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# Step 1: Push local code to GitHub
Write-Host "[1/7] Pushing local commits to GitHub..." -ForegroundColor Yellow
git push origin $GIT_BRANCH
Write-Host "  ✅ Code pushed to GitHub" -ForegroundColor Green

# Remote commands jo server par chalenge
$REMOTE_SCRIPT = @"
set -e

echo ''
echo '── Pulling latest code ──────────────────────'
cd $APP_DIR
git fetch origin
git reset --hard origin/$GIT_BRANCH
echo "  Code: `$(git rev-parse --short HEAD)"

echo ''
echo '── PHP Dependencies ─────────────────────────'
composer install --no-dev --optimize-autoloader --no-interaction --quiet
echo '  Composer done'

echo ''
echo '── Frontend Build ───────────────────────────'
npm ci --prefer-offline --silent 2>/dev/null || npm install --silent
npm run build --silent
echo '  npm build done'

echo ''
echo '── Maintenance Mode ON ──────────────────────'
php artisan down --retry=5 2>/dev/null || true

echo ''
echo '── Database Migrations ──────────────────────'
php artisan migrate --force --no-interaction
echo '  Migrations done'

echo ''
echo '── Storage & Permissions ────────────────────'
php artisan storage:link 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo ''
echo '── Cache Rebuild ────────────────────────────'
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache
php artisan event:clear
php artisan event:cache
php artisan optimize
echo '  Cache done'

echo ''
echo '── Queue Restart ────────────────────────────'
php artisan queue:restart
echo '  Queue restarted'

echo ''
echo '── Site LIVE ────────────────────────────────'
php artisan up
echo '  Done!'
"@

# Step 2: SSH into server and run
Write-Host ""
Write-Host "[2/7] Connecting to server and deploying..." -ForegroundColor Yellow
Write-Host "  (Server password manega)" -ForegroundColor Gray
Write-Host ""

$REMOTE_SCRIPT | ssh -o StrictHostKeyChecking=no "${SERVER_USER}@${SERVER_HOST}" bash

Write-Host ""
Write-Host "  ✅ Deployment complete!" -ForegroundColor Green
Write-Host "  🌐 Live at: $SITE_URL" -ForegroundColor Cyan
Write-Host ""
