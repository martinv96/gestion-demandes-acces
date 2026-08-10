# Reconstruction après modifications

```html

<!-- Pour vider le cache et reconstruire après modifications : -->
sudo rm -rf var/cache/* && sudo -u www-data APP_ENV=prod php bin/console cache:warmup

sudo systemctl status nginx php8.3-fpm mysql
sudo systemctl restart nginx php8.3-fpm

<!-- commande pour la migration: -->
sudo -u www-data APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction

<!-- vider cache / reconstruction -->
cd /opt/apps/gestion-demandes-acces
sudo chown -R www-data:www-data public/assets
sudo chmod -R u+rwX,g+rwX public/assets
sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup

```

## Configuration mail

<!-- Pour la config Mail : -->

Modifier le fichier: /.env.prod
MAILER_DSN=smtp://no-reply%40leplessistrevise.fr:Leplessis94%2A@mail.infomaniak.com:587
MAILER_FROM=no-reply@leplessistrevise.fr

<!-- pour reconnecter github si besoin : -->

git config --global --unset-all credential.helper
git config --global credential.helper store

unset GIT_ASKPASS SSH_ASKPASS VSCODE_GIT_ASKPASS_NODE VSCODE_GIT_ASKPASS_MAIN VSCODE_GIT_IPC_HANDLE

git remote -v

git push -u origin master

martinv96

<!-- refaire un token si expirer -->
TOKEN_GITHUB_A_REMPLACER (date d'expiration du dernier token au 11 aout)

<!-- pour reconnecter github si besoin : -->

git config --global --unset-all credential.helper
git config --global credential.helper store

unset GIT_ASKPASS SSH_ASKPASS VSCODE_GIT_ASKPASS_NODE VSCODE_GIT_ASKPASS_MAIN VSCODE_GIT_IPC_HANDLE

git remote -v

git push -u origin master

martinv96

<!-- refaire un token si expirer -->
TOKEN_GITHUB_A_REMPLACER (date d'expiration)

## Supprime les fichiers de l'index de Git (mais les garde sur le disque)

git rm --cached code.md
git rm --cached codes.md

## Commit la modification

git add .gitignore
git commit -m "commentaire"
