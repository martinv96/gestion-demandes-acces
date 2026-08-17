# commandes utiles

```html
```

## pour gérer le projet coté admin

<!-- Pour récupérer le projet (github) -->
le projet est récupérable depuis ce lien :
https://github.com/martinv96/gestion-demandes-acces.git

pour le cloner, se placer dans un dossier depuis un terminal, puis : git glone https://github.com/martinv96/gestion-demandes-acces.git

<!-- rappel admin -->

l'interface utilisateur dédié à l'admin est le premier outil à utiliser. Il permet :

1. d'ajouter / modifier un utilisateur
2. d'activer / désactiver un compte
3. de réinitialiser un mot de passe

en cas de soucis majeur, vous pouvez cependant réaliser ces manipulations directement en base. Il est conseillé de générer un hash (pas de texte clair directement en base de données) avec la commande :

" sudo -u www-data APP_ENV=prod php bin/console security:hash-password 'votremotdepasse' "

puis de l'update avec l'instruction SQL :

" UPDATE user SET password = 'votremotdepasse' WHERE id = 123; "

<!-- Pour modiifier les infos mail (si besoin) -->

si vous avez besoin de modifier les infos mail, aller sur le fichier .env et modifier les variables MAILER_DSN et MAILER_FROM.

Si vous avez besoin de changer les infos admin, passer par une interface MySQL (PHPMyAdmin ou DBeaver) ou passer par un terminal et se connecter à MySQL avec cette commande :

sudo mysql -u root -p

une fois connecté :" use gestion_demandes;"

puis pour changer le mot de passe : "UPDATE user SET password = 'password', must_change_password = 1 WHERE id = 123;" ;

pour changer l'adresse mail : " UPDATE user SET email = 'nouvel.email@example.fr' WHERE id = 123; "


<!-- Pour modifier le code (back ou front) -->

après toutes modifications du projet, ne pas oublier de vider le cache et reconstruire avec les commandes :

sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup

<!-- en cas de soucis important (casse logiciel) -->

consulter les logs dans var/log/prod.log

et vérifier les services : sudo systemctl status nginx php8.3-fpm mysql

## Pour aller plus loin

### vous pouvez faire un tails des logs en temps réel

sudo tail -n 200 var/log/prod.log
ou pour tout récupérer : sudo tail -f var/log/prod.log

### Pour vérifier l'espace disque et les permissions

df -h
sudo du -sh var/* | sort -h
sudo ls -la var var/cache var/log public/assets
sudo chown -R www-data:www-data var public/assets

### Pour tester la connexion à la base de données, avec requète simple

mysql -u root -p -e "USE gestion_demandes; SELECT 1;"

Si ça renvoit une erreur, récupérer l'erreur correspondante dans prod.log.

### Pour vérifier les migrations, cache et dépendances

sudo -u www-data APP_ENV=prod php bin/console doctrine:migrations:status
sudo -u www-data APP_ENV=prod php bin/console cache:clear
composer show --no-dev

### Après avoir récupérer les logs, pour redémarrer les services

sudo systemctl restart php8.3-fpm nginx mysql
sudo systemctl status php8.3-fpm nginx mysql


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

## Configuration mail

<!-- Pour la config Mail : -->

Modifier le fichier: /.env.prod
MAILER_DSN=smtp://no-reply%40leplessistrevise.fr:Leplessis94%2A@mail.infomaniak.com:587
MAILER_FROM=no-reply@leplessistrevise.fr

## Automatisation des rappels

Pour envoyer automatiquement les relances de demandes bloquées, planifiez la commande suivante en cron :

```bash
cd /chemin/vers/gestion-demandes-acces
/bin/bash bin/send-reminders-cron.sh
```

Exemple de crontab (toutes les heures) :

```cron
0 * * * * /bin/bash /chemin/vers/gestion-demandes-acces/bin/send-reminders-cron.sh
```

Journaux d'exécution : `var/log/workflow-reminders.log`

## pour reconnecter github si besoin

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

git add .
git commit -m "commentaire"
