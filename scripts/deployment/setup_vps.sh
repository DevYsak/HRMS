#!/bin/bash

# ==============================================================================
# VPS Setup Script (Run as root)
# Purpose: Prepares the server for multiple Laravel projects with a 'deploy' user.
# ==============================================================================

set -e

# Configuration
DEPLOY_USER="deploy"
PHP_VERSION="8.3"

echo "--- Starting VPS Setup ---"

# 1. Update system
apt update && apt upgrade -y

# 2. Install dependencies
apt install -y nginx git unzip curl software-properties-common ca-certificates lsb-release

# 3. Install PHP 8.3
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-common \
    php${PHP_VERSION}-mysql php${PHP_VERSION}-xml php${PHP_VERSION}-curl \
    php${PHP_VERSION}-mbstring php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-intl php${PHP_VERSION}-gd php${PHP_VERSION}-sqlite3

# 4. Install Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# 5. Create Deploy User
if id "$DEPLOY_USER" &>/dev/null; then
    echo "User $DEPLOY_USER already exists."
else
    adduser --disabled-password --gecos "" $DEPLOY_USER
    usermod -aG sudo $DEPLOY_USER
    usermod -aG www-data $DEPLOY_USER
    echo "User $DEPLOY_USER created and added to sudo and www-data groups."
fi

# 6. Setup Directory Structure
mkdir -p /var/www
chown root:root /var/www
chmod 755 /var/www

# 7. Setup SSH for Deploy User
mkdir -p /home/$DEPLOY_USER/.ssh
chmod 700 /home/$DEPLOY_USER/.ssh
touch /home/$DEPLOY_USER/.ssh/authorized_keys
chmod 600 /home/$DEPLOY_USER/.ssh/authorized_keys
chown -R $DEPLOY_USER:$DEPLOY_USER /home/$DEPLOY_USER/.ssh

echo "--- Setup Complete ---"
echo "IMPORTANT: Please add your public SSH key to /home/$DEPLOY_USER/.ssh/authorized_keys"
echo "Then, you can disable root login in /etc/ssh/sshd_config."
