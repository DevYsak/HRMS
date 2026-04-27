#!/bin/bash

# ==============================================================================
# Project Deployment Script (Run as deploy user)
# Usage: ./deploy_project.sh <project_name> <git_repo_url>
# ==============================================================================

set -e

if [ -z "$1" ] || [ -z "$2" ]; then
    echo "Usage: ./deploy_project.sh <project_name> <git_repo_url>"
    exit 1
fi

PROJECT_NAME=$1
REPO_URL=$2
TARGET_DIR="/var/www/$PROJECT_NAME"

echo "--- Deploying Project: $PROJECT_NAME ---"

# 1. Clone or update repository
if [ -d "$TARGET_DIR" ]; then
    echo "Updating existing directory..."
    cd "$TARGET_DIR"
    git pull origin main
else
    echo "Cloning repository..."
    git clone "$REPO_URL" "$TARGET_DIR"
    cd "$TARGET_DIR"
fi

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Environment Setup
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cp .env.example .env
    php artisan key:generate
    echo "IMPORTANT: Update your .env file with production credentials!"
fi

# 4. Set Permissions
echo "Setting permissions..."
chown -R deploy:www-data "$TARGET_DIR"
find "$TARGET_DIR" -type f -exec chmod 644 {} \;
find "$TARGET_DIR" -type d -exec chmod 755 {} \;

# Storage and Cache specifically
chmod -R 775 "$TARGET_DIR/storage"
chmod -R 775 "$TARGET_DIR/bootstrap/cache"

# 5. Run Migrations (optional/interactive)
# php artisan migrate --force

echo "--- Deployment Complete for $PROJECT_NAME ---"
echo "Next steps:"
echo "1. Update /var/www/$PROJECT_NAME/.env with database credentials."
echo "2. Create Nginx config in /etc/nginx/sites-available/$PROJECT_NAME using the template."
echo "3. Link it: ln -s /etc/nginx/sites-available/$PROJECT_NAME /etc/nginx/sites-enabled/"
echo "4. Reload Nginx: sudo systemctl reload nginx"
