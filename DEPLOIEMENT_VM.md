# Déploiement du projet dans une VM

Ce document présente deux façons de déployer l'application Symfony dans une machine virtuelle Linux.

- Option 1 : Symfony installé directement sur la VM avec MySQL et Nginx. C'est l'option la plus simple et la plus adaptée à l'état actuel du projet.
- Option 2 : application et base déployées avec Docker Compose. Cette option est plus avancée et demande davantage de préparation.

L'application utilise actuellement MySQL dans sa configuration Symfony. La documentation ci-dessous est donc alignée sur MySQL.

## Choix recommandé

L'option 1 est recommandée pour ce projet car :

- elle est plus proche de l'environnement de développement actuel ;
- elle demande moins de refonte de l'infrastructure ;
- elle est plus simple à mettre en place, à déboguer et à expliquer ;
- les fichiers Docker actuels ont été générés par défaut et ne correspondent pas encore à l'architecture réelle du projet.

## Option 1 : Symfony directement sur la VM

Dans cette option, l'application Symfony tourne directement sur la VM.

Architecture :

- Nginx sert les fichiers publics et transmet les requêtes PHP à PHP-FPM.
- PHP-FPM exécute l'application Symfony.
- MySQL tourne sur la même VM.
- Si MySQL est installé sur la même VM, la variable `DB_HOST` doit être `127.0.0.1`.

### 1. Pré-requis VM

- VM Ubuntu 22.04 ou 24.04
- accès SSH
- utilisateur avec droits sudo
- ports ouverts : 22, 80, 443

### 2. Installer les paquets nécessaires

Commandes complètes :

```bash
sudo apt update
sudo apt upgrade -y

sudo apt install -y software-properties-common ca-certificates lsb-release apt-transport-https unzip curl git gnupg

sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y nginx mysql-server \
  php8.3 php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mysql php8.3-mbstring php8.3-intl php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-opcache

cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php

sudo systemctl enable nginx
sudo systemctl enable mysql
sudo systemctl enable php8.3-fpm

sudo systemctl start nginx
sudo systemctl start mysql
sudo systemctl start php8.3-fpm

php -v
composer --version
mysql --version
nginx -v
systemctl status nginx --no-pager
systemctl status mysql --no-pager
systemctl status php8.3-fpm --no-pager
```

### 3. Récupérer le projet

```bash
cd /opt
sudo mkdir -p apps
sudo chown -R $USER:$USER /opt/apps
cd /opt/apps

git clone <URL_DU_REPO> gestion-demandes-acces
cd gestion-demandes-acces
```

### 4. Installer les dépendances PHP

```bash
cd /opt/apps/gestion-demandes-acces
composer install --no-dev --optimize-autoloader
```

### 5. Configurer l'environnement de production

Créer le fichier `.env.prod.local` :

```bash
cd /opt/apps/gestion-demandes-acces
cat > .env.prod.local <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=CHANGE_ME_LONG_RANDOM_SECRET

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=gestion_demandes
DB_USER=app
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

DATABASE_URL="mysql://app:CHANGE_ME_STRONG_PASSWORD@127.0.0.1:3306/gestion_demandes?serverVersion=8.0.45&charset=utf8mb4"
MAILER_DSN=null://null
EOF

cat .env.prod.local
```

Points importants :

- ne pas stocker les secrets dans le dépôt Git ;
- `APP_ENV` doit être `prod` ;
- `APP_DEBUG` doit être `0` ;
- si la base est sur la même VM, `DB_HOST` doit rester `127.0.0.1`.

### 6. Créer la base MySQL

Commandes complètes :

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS gestion_demandes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';"
sudo mysql -e "GRANT ALL PRIVILEGES ON gestion_demandes.* TO 'app'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
sudo mysql -e "SHOW DATABASES LIKE 'gestion_demandes';"
```

Si vous utilisez `root` à la place, adaptez `DB_USER` et `DB_PASSWORD` dans le fichier d'environnement.

### 7. Initialiser la base Symfony

```bash
cd /opt/apps/gestion-demandes-acces
php bin/console about
php bin/console doctrine:database:create --if-not-exists --env=prod
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
php bin/console debug:router
```

### 8. Régler les permissions

Symfony doit pouvoir écrire dans `var/cache` et `var/log`.

```bash
cd /opt/apps/gestion-demandes-acces
sudo chown -R $USER:www-data .
sudo chown -R www-data:www-data var
sudo chmod -R 775 var
sudo find var -type d -exec chmod 775 {} \;
sudo find var -type f -exec chmod 664 {} \;
```

### 9. Configurer Nginx

Le point critique est le suivant : Nginx doit pointer vers le dossier `public/` du projet, jamais vers la racine du dépôt.

Créer le fichier de configuration :

```bash
sudo tee /etc/nginx/sites-available/gestion-demandes > /dev/null <<'EOF'
server {
  listen 80;
  server_name _;

  root /opt/apps/gestion-demandes-acces/public;
  index index.php;

  location / {
    try_files $uri /index.php$is_args$args;
  }

  location ~ ^/index\.php(/|$) {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT $realpath_root;
    internal;
  }

  location ~ \.php$ {
    return 404;
  }

  access_log /var/log/nginx/gestion-demandes-access.log;
  error_log /var/log/nginx/gestion-demandes-error.log;
}
EOF

sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf /etc/nginx/sites-available/gestion-demandes /etc/nginx/sites-enabled/gestion-demandes
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
sudo systemctl restart nginx
```

### 10. Vérifications après déploiement

Commandes de contrôle :

```bash
cd /opt/apps/gestion-demandes-acces
php bin/console doctrine:query:sql "SELECT 1"
php bin/console cache:clear --env=prod
curl -I http://127.0.0.1
curl -I http://<IP_DE_LA_VM>
sudo tail -n 100 /var/log/nginx/gestion-demandes-error.log
tail -n 100 var/log/prod.log
```

Contrôles minimums à faire :

- page de login accessible ;
- connexion fonctionnelle ;
- accès base de données correct ;
- formulaires opérationnels ;
- exports opérationnels ;
- pas d'erreurs critiques dans les logs.

### 11. Mise à jour applicative

Pour mettre à jour l'application :

```bash
cd /opt/apps/gestion-demandes-acces
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
```

### 12. HTTPS

En production, il est recommandé d'ajouter HTTPS avec Let's Encrypt.

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d exemple.fr -d www.exemple.fr
sudo certbot renew --dry-run
```

## Option 2 : déploiement complet avec Docker

Dans cette option, l'application et la base tournent toutes les deux dans des conteneurs.

Architecture :

- un conteneur PHP pour Symfony ;
- un conteneur web Nginx ou Caddy ;
- un conteneur MySQL ;
- les services communiquent via le réseau Docker.

Dans cette option, `DB_HOST` ne vaut plus `127.0.0.1` mais `database`, car `database` est le nom du service Docker Compose.

### 1. Pré-requis spécifiques

Avant d'utiliser cette option, il faut préparer une vraie stack Docker complète. Les fichiers `compose.yaml` et `compose.override.yaml` générés par défaut ne suffisent pas dans l'état actuel du projet.

Il faut au minimum :

- un service `database` cohérent avec MySQL ;
- un service PHP pour exécuter Symfony ;
- éventuellement un service web Nginx ;
- un Dockerfile applicatif avec les extensions PHP nécessaires.

Commandes de préparation minimales une fois les fichiers Docker corrigés :

```bash
cd /opt/apps/gestion-demandes-acces
docker compose config
docker compose config --services
```

### 2. Installer Docker sur la VM

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg lsb-release git

sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo $VERSION_CODENAME) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

sudo usermod -aG docker $USER
newgrp docker

docker --version
docker compose version
```

### 3. Récupérer le projet

```bash
cd /opt
sudo mkdir -p apps
sudo chown -R $USER:$USER /opt/apps
cd /opt/apps

git clone <URL_DU_REPO> gestion-demandes-acces
cd gestion-demandes-acces
```

### 4. Configurer les variables d'environnement

```bash
cd /opt/apps/gestion-demandes-acces
cat > .env.prod.local <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=CHANGE_ME_LONG_RANDOM_SECRET

DB_HOST=database
DB_PORT=3306
DB_NAME=gestion_demandes
DB_USER=app
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

DATABASE_URL="mysql://app:CHANGE_ME_STRONG_PASSWORD@database:3306/gestion_demandes?serverVersion=8.0.45&charset=utf8mb4"
MAILER_DSN=null://null
EOF

cat .env.prod.local
```

### 5. Démarrer les services

```bash
cd /opt/apps/gestion-demandes-acces
docker compose pull
docker compose build --no-cache
docker compose up -d
docker compose ps
docker compose logs --tail=100
```

### 6. Installer les dépendances et initialiser Symfony

Si le service PHP s'appelle `php`, utiliser :

```bash
cd /opt/apps/gestion-demandes-acces
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php bin/console doctrine:database:create --if-not-exists --env=prod
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console cache:warmup --env=prod
docker compose exec php php bin/console debug:router
```

### 7. Exposer l'application

Deux approches sont possibles :

- exposer directement le conteneur web ;
- placer un reverse proxy devant l'application.

L'approche la plus propre en production reste un reverse proxy avec HTTPS.

### 8. Sauvegarde et données persistantes

À prévoir dans la stack Docker :

- un volume pour MySQL ;
- éventuellement d'autres volumes si l'application stocke des fichiers persistants.

Exemple de sauvegarde :

```bash
cd /opt/apps/gestion-demandes-acces
docker compose exec -T database mysqldump -uapp -pCHANGE_ME_STRONG_PASSWORD gestion_demandes > backup_$(date +%F).sql
```

### 9. Mise à jour applicative

```bash
cd /opt/apps/gestion-demandes-acces
git pull
docker compose build
docker compose up -d
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console cache:warmup --env=prod
docker compose logs --tail=100
```

## Résumé

Choisir l'option 1 si :

- l'objectif est d'aller au plus simple ;
- l'application doit être déployée rapidement ;
- vous voulez rester proche de l'environnement local actuel ;
- vous devez présenter une architecture compréhensible facilement.

Choisir l'option 2 si :

- Docker est imposé ;
- vous voulez une stack plus portable et plus industrialisée ;
- vous acceptez de préparer une infrastructure plus complète.

Dans l'état actuel du projet, l'option 1 est la voie recommandée.

## Dépannage rapide

- `Could not open input file: bin/console`
  - Vous n'êtes pas dans le bon dossier. Placez-vous dans le répertoire `gestion-demandes-acces`.

- Erreur de connexion MySQL
  - Vérifier `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` et `DATABASE_URL`.

- Symfony fonctionne en local mais pas sur la VM
  - Vérifier le fichier `.env.local` ou `.env.prod.local`, les permissions du dossier `var/`, et la configuration Nginx.

- En Docker, la base n'est pas joignable
  - Vérifier que `DB_HOST=database` et que le service `database` est bien démarré.
