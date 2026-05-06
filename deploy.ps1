# ============================================================
#  PULSE HRMS - Deploy Script (PowerShell)
#  Run: .\deploy.ps1
#  Optional: $env:RUN_SEEDERS="true"; .\deploy.ps1
# ============================================================

$ErrorActionPreference = "Stop"

$SERVER_USER = "ysak"
$SERVER_HOST = "69.62.74.243"
$APP_DIR     = "/home/ysak/app/nexc"
$GIT_BRANCH  = "staging"
$SITE_URL    = "https://nexcore.wayforweb.tech"
$RUN_SEEDERS = if ($env:RUN_SEEDERS) { $env:RUN_SEEDERS } else { "false" }

function Fail($message) {
    Write-Host "  FAIL $message" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host "  PULSE HRMS DEPLOY" -ForegroundColor Cyan
Write-Host "  $SITE_URL" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host ""

$LOCAL_BRANCH = (git rev-parse --abbrev-ref HEAD).Trim()
$LOCAL_COMMIT = (git rev-parse --short HEAD).Trim()

Write-Host "  Local branch : $LOCAL_BRANCH" -ForegroundColor Yellow
Write-Host "  Local commit : $LOCAL_COMMIT" -ForegroundColor Yellow
Write-Host "  Deploy branch: $GIT_BRANCH" -ForegroundColor Yellow

git diff --quiet
if ($LASTEXITCODE -ne 0) {
    Fail "Working tree clean nahi hai. Pehle changes commit karo."
}

git diff --cached --quiet
if ($LASTEXITCODE -ne 0) {
    Fail "Staged but uncommitted changes hain. Pehle commit karo."
}

$UNTRACKED = git ls-files --others --exclude-standard
if ($UNTRACKED) {
    Fail "Untracked files mile hain. Unhe add/commit ya ignore karke phir deploy chalao."
}

if ($LOCAL_BRANCH -ne $GIT_BRANCH) {
    $confirm = Read-Host "Aap '$LOCAL_BRANCH' par ho, deploy '$GIT_BRANCH' branch ka hoga. Continue? (y/N)"
    if ($confirm -notmatch '^[Yy]$') {
        Write-Host "Aborted."
        exit 0
    }
}

Write-Host ""
Write-Host "  Starting deployment at $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Yellow

Write-Host ""
Write-Host "[1/9] Pushing committed changes to GitHub..." -ForegroundColor Yellow
git push origin $GIT_BRANCH
if ($LASTEXITCODE -ne 0) {
    Fail "Git push failed."
}
Write-Host "  OK Code pushed to GitHub" -ForegroundColor Green

$REMOTE_SCRIPT = @"
set -euo pipefail

cd "$APP_DIR"

cleanup() {
    php artisan up >/dev/null 2>&1 || true
}

trap cleanup EXIT

echo ''
echo '-- Maintenance Mode ON'
php artisan down --retry=5 2>/dev/null || true

echo ''
echo '-- Pull latest code'
git fetch origin
git reset --hard origin/$GIT_BRANCH
echo "  Code: \$(git rev-parse --short HEAD)"

echo ''
echo '-- Install PHP dependencies'
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
echo '  Composer install complete'

echo ''
echo '-- Build frontend assets'
if [ -f "package.json" ]; then
    npm ci --prefer-offline || npm install
    npm run build
    echo '  Frontend build complete'
else
    echo '  package.json not found, skipping npm build'
fi

echo ''
echo '-- Run database migrations'
php artisan migrate --force --no-interaction
echo '  Migrations applied'

if [ "$RUN_SEEDERS" = "true" ]; then
    echo ''
    echo '-- Run database seeders'
    php artisan db:seed --force --no-interaction
    echo '  Seeders executed'
fi

echo ''
echo '-- Storage and permissions'
php artisan storage:link 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
echo '  Storage linked and permissions updated'

echo ''
echo '-- Rebuild Laravel caches'
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan optimize
echo '  Caches rebuilt'

echo ''
echo '-- Restart queue workers'
php artisan queue:restart
echo '  Queue workers restarted'

echo ''
echo '-- Maintenance Mode OFF'
php artisan up
echo '  Site is live'
"@

Write-Host ""
Write-Host "[2/9] Connecting to server and deploying..." -ForegroundColor Yellow
Write-Host "  Server password ya SSH key use hogi" -ForegroundColor DarkGray
Write-Host ""

$REMOTE_SCRIPT | ssh -o StrictHostKeyChecking=no "${SERVER_USER}@${SERVER_HOST}" bash
if ($LASTEXITCODE -ne 0) {
    Fail "Remote deployment failed."
}

Write-Host ""
Write-Host "[9/9] Deployment complete" -ForegroundColor Yellow
Write-Host "  OK Deployment finished successfully" -ForegroundColor Green
Write-Host "  Live at: $SITE_URL" -ForegroundColor Cyan
Write-Host "  Completed at $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Yellow
Write-Host ""
