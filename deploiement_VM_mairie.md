# 1. INSTALLATION SYSTEME & COMPOSER

## Met à jour la liste des paquets et met à jour le système

sudo apt update && sudo apt upgrade -y

## Installe Nginx, MySQL, les outils système et PHP 8.2 avec toutes les extensions nécessaires à Symfony

sudo apt install -y nginx mysql-server unzip curl git php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-intl php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-opcache



## Télécharge et installe Composer (gestionnaire de dépendances PHP)

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

## 2. RECUPERATION DU CODE ET DEPENDANCES PHP

## Prépare le dossier d'applications et donne les droits à l'utilisateur courant

cd /opt && sudo mkdir -p apps && sudo chown $USER:$USER /opt/apps

## Clone le dépôt du projet (remplace <URL_REPO> par l'URL réelle)

git clone <URL_REPO> gestion-demandes-acces
cd /opt/apps/gestion-demandes-acces

## Installe les dépendances PHP du projet en mode production

composer install --no-dev --optimize-autoloader

## 3. CONFIGURATION ENVIRONNEMENT (.env.prod.local)

## Crée le fichier d'environnement de production avec les variables nécessaires

cat > .env.prod.local <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="mysql://app:MotDePasseFort2026!@127.0.0.1:3306/gestion_demandes?serverVersion=8.0.45&charset=utf8mb4"
DEFAULT_URI=<http://192.168.0.4>
EOF

## 4. BASE DE DONNEES & STRUCTURE

## Crée la base de données, l'utilisateur MySQL et applique les droits

sudo mysql -e "CREATE DATABASE IF NOT EXISTS gestion_demandes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'MotDePasseFort2026!';"
sudo mysql -e "GRANT ALL PRIVILEGES ON gestion_demandes.* TO 'app'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

## Applique les migrations Doctrine pour créer la structure de la base

php bin/console doctrine:migrations:migrate --no-interaction --env=prod

## 5. INITIALISATION DATA (ROLES / SERVICE / ADMIN)
<!-- On doit reprendre ici -->

## Ajoute les rôles et un service par défaut dans la base

sudo mysql -D gestion_demandes -e "INSERT INTO role (label) VALUES ('ROLE_ADMIN'), ('ROLE_USER');"
sudo mysql -D gestion_demandes -e "INSERT INTO service (name, email, code) VALUES ('Informatique', '<it@mairie.fr>', 'DSI');"

## Note : Générez le hash du mot de passe admin avec la commande suivante

php bin/console security:hash-password

### Puis injectez l'utilisateur admin avec le hash généré

sudo mysql -D gestion_demandes -e "INSERT INTO user (firstname, lastname, email, password, is_active, role_id, service_id, must_change_password) VALUES ('Admin', 'Nom', '<admin@mairie.fr>', 'TON_HASH_ICI', 1, 1, 1, 0);"

## 6. ASSETS (BOOTSTRAP) ET PERMISSIONS

### Installe et compile les assets Symfony pour la production

php bin/console importmap:install --env=prod
php bin/console asset-map:compile --env=prod

### Donne les bons droits aux dossiers nécessaires à l'exécution de Symfony

sudo chown -R www-data:www-data /opt/apps/gestion-demandes-acces
sudo chmod -R 775 /opt/apps/gestion-demandes-acces/var
sudo chmod -R 775 /opt/apps/gestion-demandes-acces/public/assets

## 7. CONFIGURATION NGINX

### Crée la configuration Nginx pour le projet Symfony (adapter le chemin du socket PHP si besoin)

sudo tee /etc/nginx/sites-available/gestion-demandes > /dev/null <<'EOF'
server {
    listen 80;
    server_name_;
    root /opt/apps/gestion-demandes-acces/public;
    index index.php;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
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

### Active la configuration et redémarre Nginx et PHP-FPM

sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/gestion-demandes /etc/nginx/sites-enabled/gestion-demandes
sudo nginx -t && sudo systemctl restart nginx php8.2-fpm
