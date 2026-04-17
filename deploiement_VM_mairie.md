# 1. INSTALLATION SYSTEME & COMPOSER
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server unzip curl git php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-intl php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-opcache
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# 2. RECUPERATION DU CODE ET DEPENDANCES PHP
cd /opt && sudo mkdir -p apps && sudo chown $USER:$USER /opt/apps
git clone <URL_REPO> gestion-demandes-acces
cd /opt/apps/gestion-demandes-acces
composer install --no-dev --optimize-autoloader

# 3. CONFIGURATION ENVIRONNEMENT (.env.prod.local)
cat > .env.prod.local <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://app:MotDePasseFort2026!@127.0.0.1:3306/gestion_demandes?serverVersion=8.0.45&charset=utf8mb4"
DEFAULT_URI=http://192.168.0.4
EOF

# 4. BASE DE DONNEES & STRUCTURE
sudo mysql -e "CREATE DATABASE IF NOT EXISTS gestion_demandes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'MotDePasseFort2026!';"
sudo mysql -e "GRANT ALL PRIVILEGES ON gestion_demandes.* TO 'app'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# 5. INITIALISATION DATA (ROLES / SERVICE / ADMIN)
sudo mysql -D gestion_demandes -e "INSERT INTO role (label) VALUES ('ROLE_ADMIN'), ('ROLE_USER');"
sudo mysql -D gestion_demandes -e "INSERT INTO service (name, email, code) VALUES ('Informatique', 'it@mairie.fr', 'DSI');"
# Note: Genere ton hash avec: php bin/console security:hash-password
# Puis injecte: sudo mysql -D gestion_demandes -e "INSERT INTO user (firstname, lastname, email, password, is_active, role_id, service_id, must_change_password) VALUES ('Admin', 'Nom', 'admin@mairie.fr', 'TON_HASH_ICI', 1, 1, 1, 0);"

# 6. ASSETS (BOOTSTRAP) ET PERMISSIONS
php bin/console importmap:install --env=prod
php bin/console asset-map:compile --env=prod
sudo chown -R www-data:www-data /opt/apps/gestion-demandes-acces
sudo chmod -R 775 /opt/apps/gestion-demandes-acces/var
sudo chmod -R 775 /opt/apps/gestion-demandes-acces/public/assets

# 7. CONFIGURATION NGINX
sudo tee /etc/nginx/sites-available/gestion-demandes > /dev/null <<'EOF'
server {
    listen 80;
    server_name _;
    root /opt/apps/gestion-demandes-acces/public;
    index index.php;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }
    location ~ \.php$ { return 404; }
    access_log /var/log/nginx/gestion-demandes-access.log;
    error_log /var/log/nginx/gestion-demandes-error.log;
}
EOF
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/gestion-demandes /etc/nginx/sites-enabled/gestion-demandes
sudo nginx -t && sudo systemctl restart nginx php8.3-fpm