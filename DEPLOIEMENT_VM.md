# Deploiement du projet dans une VM (Ubuntu + Docker)

Ce guide deploie l'application Symfony dans une VM Linux en utilisant Docker Compose.

## 1) Pre-requis VM

- VM Ubuntu 22.04 ou 24.04
- Acces SSH
- Ports ouverts: 22 (SSH), 80 (HTTP), 443 (HTTPS)
- Nom de domaine (recommande pour HTTPS)

## 2) Installer Docker + Compose

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

## 3) Recuperer le projet

```bash
cd /opt
sudo mkdir -p apps
sudo chown -R $USER:$USER /opt/apps
cd /opt/apps

git clone <URL_DU_REPO> gestion-demandes-acces
cd gestion-demandes-acces
```

## 4) Configurer les variables d'environnement

Creer un fichier `.env.local` (ou `.env.prod.local`) dans le projet.

Exemple minimal:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=CHANGE_ME_LONG_RANDOM_SECRET

POSTGRES_DB=app
POSTGRES_USER=app
POSTGRES_PASSWORD=CHANGE_ME_STRONG_PASSWORD
POSTGRES_VERSION=16

# A adapter selon votre config Doctrine actuelle
DATABASE_URL="postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=16&charset=utf8"
```

Important:
- Les commandes doivent etre lancees depuis le dossier `gestion-demandes-acces`.
- Le `DATABASE_URL` doit correspondre au moteur reel (PostgreSQL dans `compose.yaml`).

## 5) Demarrer les conteneurs

```bash
docker compose pull
docker compose up -d

docker compose ps
```

## 6) Installer les dependances Symfony et initialiser la base

Si l'application tourne dans un conteneur PHP, executer les commandes dans ce conteneur.

1. Identifier le nom du service PHP:
```bash
docker compose config --services
```

2. Lancer les commandes Symfony (adapter `<php_service>`):
```bash
docker compose exec <php_service> composer install --no-dev --optimize-autoloader
docker compose exec <php_service> php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec <php_service> php bin/console cache:clear --env=prod
docker compose exec <php_service> php bin/console cache:warmup --env=prod
```

## 7) Exposer l'application

Option simple (test):
- Exposer directement le port web de votre service applicatif.

Option recommandee (prod):
- Reverse proxy Nginx ou Caddy devant l'application.
- HTTPS Lets Encrypt.

## 8) Mise a jour applicative

```bash
cd /opt/apps/gestion-demandes-acces
git pull

docker compose up -d --build

docker compose exec <php_service> php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec <php_service> php bin/console cache:clear --env=prod
docker compose exec <php_service> php bin/console cache:warmup --env=prod
```

## 9) Sauvegarde et logs

Sauvegarde base PostgreSQL:
```bash
docker compose exec -T database pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > backup_$(date +%F).sql
```

Logs:
```bash
docker compose logs -f
```

## 10) Checklist production

- `APP_ENV=prod` et `APP_DEBUG=0`
- Secret et mots de passe forts
- HTTPS actif
- Pas d'exposition publique du port database
- Sauvegardes planifiees
- Monitoring des logs et redemarrage automatique des conteneurs

## Depannage rapide

- `Could not open input file: bin/console`
  - Vous n'etes pas dans le bon dossier. Faites `cd /opt/apps/gestion-demandes-acces`.

- Erreur de connexion base
  - Verifier `DATABASE_URL`, variables `POSTGRES_*`, et que le service `database` est `healthy`.

- Migrations ko
  - Verifier que les identifiants DB sont corrects et que le conteneur PHP peut joindre `database:5432`.
