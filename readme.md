# GDAP - Gestion des Demandes Des Acces Du Plessis

Application Symfony d'administration et de gestion des demandes d'accès pour la collectivité.

---

## Fonctionnalités

- **Gestion des demandes d'accès:** Création, validation et historique des demandes d'accès
- **Espace d'administration complet :** Création, modification et suppression/désactivation/activation de comptes; création, modification et suppresion de services; création, modification et suppréssion de logiciels; audit de l'activité de connexion
- **Notification e-mail :** Envoi de mails de notification lors de validation / mise à jour des demandes en attentes / traitées via infomaniak SMTP (`symfony/mailer`).
- **Export & reporting :** Génération de tableaux au format xls via `phpoffice/phpspreadsheet`.

---

## Stack Technique & infrastructure

- **Framework :** Symfony 7.4 (LTS)
- **Langage :** PHP 8.3
- **Base de données :** MySQl
- **Serveur web :** Nginx + PHP-FPM
- **Serveur distant :** Linux VM (`192.168.1.229`)
- **Dossier racine :** `/opt/apps/gestion-demandes-acces`

---

## Choix techniques

- **AssetMapper & ImportMap :** Aucune dépendance Node.js/npm en production. Les assets JavaScript (Stimulus, Turbo) sont directement servis par Symfony.
- **E-mails transactionnels avancés :** Utilisation de Inline CSS dans twig pour garantir un rendu HTML compatible avec tous les clients e-mails.
- **Maintenance à long terme :** Architechture prête pour les futurs mises à jour mineurs / majeurs vers Symfony 8.4 avec prise en charge des dépréciations.
- **Sécurité renforcée :** Mots de passe hashés via le composant Security natif de Symfony et gestion des variables sensibles via `.env.prod`.

---

## Documentation utilisées

- [Documentation Symfony 7.4](https://symfony.com/doc/7.4/index.html)
- [Documentation Doctrine ORM](https://www.doctrine-project.org/)
- [AssetMapper Component Guide](https://symfony.com/doc/current/frontend/asset_mapper.html)
- [PhpSpreadsheet documentation](https://phpspreadsheet.readthedocs.io/)
- [PHPUnit 11 Documentation](https://phpunit.de/documentation.html)

---

## Déploiement & serveur de production

- **Environnement :** VM Interne / Application privée
- **Repertoire de l'application :** `opt/apps/gestion-demandes-acces`
- **Répertoire des sauvegardes :** `opt/apps/backups`

## Commandes utiles

## Pour se connecter à la machine distante

Sur vs code, avec l'extension Remote SSH : nouvelle connexion adresse IP 192.168.1.229

### Pour effectuer la maintenance à moyen terme (Mises à jour de sécurité jusqu'en 2029)

**Fréquence :** Tous les 3 à 6 mois

Commandes à exécuter :

```bash
cd /opt/apps/gestion-demandes-acces
sudo -u www-data composer update --no-dev
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup
```

### Pour la maintenance à long terme

Corriger les dépreciations avec la commande :

```bash
sudo -u www-data APP_ENV=prod php bin/console debug:container --deprecations
```

Puis avec la commande :

```bash
sudo -u www-data composer config extra.symfony.require "8.4.*"
sudo -u www-data composer update "symfony/*" --with-all-dependencies
sudo -u www-data APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
```

Puis, reconstruire les assets et vider le cache :

```bash
sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup
```

Ensuite, tous les 3 à 6 mois reprendre les commandes :

```bash
sudo -u www-data composer update --no-dev
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup
```

## Pour gérer le projet coté admin

Le projet est récupérable depuis ce lien :

```bash
https://github.com/martinv96/gestion-demandes-acces.git
```

Pour le cloner, se placer dans un dossier depuis un terminal, puis :

```bash
git clone https://github.com/martinv96/gestion-demandes-acces.git
```

## Rappel admin

L'interface utilisateur dédié à l'admin est le premier outil à utiliser. Il permet :

1. d'ajouter / modifier un utilisateur
2. d'activer / désactiver un compte
3. de réinitialiser un mot de passe

En cas de soucis majeur, il est possible de réaliser ces manipulations directement en base. Je conseille de générer un hash (pas de texte clair directement en base de données) avec la commande :

```bash
sudo -u www-data APP_ENV=prod php bin/console security:hash-password 'votremotdepasse'
```

Puis de l'update avec l'instruction SQL :

```bash
UPDATE user SET password = 'motdepasseHASH' WHERE id = 123;
```

## Pour modifier les infos mail (si besoin)

Si besoin de modifier les infos mail, aller sur le fichier .env.prod et modifier les variables MAILER_DSN et MAILER_FROM.

Si besoin de changer les infos admin, passer par une interface MySQL (PHPMyAdmin ou DBeaver) ou passer par un terminal et se connecter à MySQL avec cette commande :

```bash
sudo mysql -u root -p
```

Une fois connecté :

```bash
use gestion_demandes;
```

Puis pour changer le mot de passe :

```bash
UPDATE user SET password = 'password', must_change_password = 1 WHERE id = 123;
```

Pour changer l'adresse mail :

```bash
UPDATE user SET email = 'nouvel.email@example.fr' WHERE id = 123;
```

### Pour modifier le code (back ou front)

Gestion des droits :

```bash
cd /opt/apps/gestion-demandes-acces
sudo chown -R www-data:www-data public/assets
sudo chmod -R u+rwX,g+rwX public/assets
```

Après toutes modifications du projet, ne pas oublier de vider le cache et reconstruire avec les commandes :

```bash
sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup
```

### En cas de souci important (casse logicielle)

Consulter les logs dans /opt/apps/gestion-demandes-acces/var/share/prod.log

Puis, vérifier les services :

```bash
sudo systemctl status nginx php8.3-fpm mysql
```

## Pour aller plus loin

### Pour récupérer les dernières entrées de logs

```bash
sudo tail -n 200 /opt/apps/gestion-demandes-acces/var/share/prod.log
```

Ou pour suivre les logs en temps réel (appuyer sur CTRL + C pour quitter) :

```bash
sudo tail -f /opt/apps/gestion-demandes-acces/var/share/prod.log
```

### Pour vérifier l'espace disque et les permissions

```bash
# Vérifier le stockage restant sur le serveur
df -h
# Taille des dossiers de logs et de cache
sudo du -sh /opt/apps/gestion-demandes-acces/var/* | sort -h
# Réparer les permissions des dossiers sensibles (à faire en cas d'erreur 500 soudaine)
cd /opt/apps/gestion-demandes-acces
sudo chown -R www-data:www-data var public/assets
sudo chmod -R 775 var public/assets
```

### Pour tester la connexion à la base de données, avec requète simple

```bash
sudo mysql -u root -p -e "USE gestion_demandes; SELECT 1;"
```

Si ça renvoit une erreur, elle s'affichera directement dans le terminal.

### Pour vérifier les migrations, cache et dépendances

```bash
# Statut des migrations Doctrine
sudo -u www-data APP_ENV=prod php bin/console doctrine:migrations:status
# Liste des paquets installés en prod
sudo -u www-data composer show --no-dev
```

### Après avoir récupéré les logs, pour redémarrer les services

```bash
sudo systemctl restart php8.3-fpm nginx mysql
sudo systemctl status php8.3-fpm nginx mysql
```

### Pour vider le cache et reconstruire après modifications

```bash
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup

sudo systemctl status nginx php8.3-fpm mysql
sudo systemctl restart nginx php8.3-fpm
```

### Commande pour la migration

```bash
sudo -u www-data APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
```

### Puis pour vider cache / reconstruction

```bash
cd /opt/apps/gestion-demandes-acces
sudo chown -R www-data:www-data public/assets
sudo chmod -R u+rwX,g+rwX public/assets
sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup
```

## Base de données - Sauvegarde et restauration

Une sauvegarde automatique permet de récupérer une version antérieure sur les 30 derniers jours.

```bash
# Si besoin, pour créer une sauvegarde manuelle dans le dossier backup
sudo mysqldump -u root -p gestion_demandes > /opt/apps/backups/db_backups_$(date +%Y%m%d_%H%M%S).sql

# Lister les sauvegardes disponibles
ls -lh /opt/apps/backups/*.sql

# Pour restaurer une version spécifique
sudo mysql -u root -p gestion_demandes < /opt/apps/backups/NOM_DU_FICHIER.sql
```

## Configuration mail

Pour la config Mail :

Modifier le fichier: /.env.prod

```bash
MAILER_DSN=smtp://no-reply%40leplessistrevise.fr:motdepasseSMTP@mail.infomaniak.com:587
MAILER_FROM=no-reply@leplessistrevise.fr
```

## Automatisation des rappels

Pour envoyer automatiquement les relances de demandes bloquées, planifiez la commande suivante en cron :

```bash
cd /opt/apps/gestion-demandes-acces
/bin/bash bin/send-reminders-cron.sh
```

Exemple de crontab (toutes les heures) :

```bash
0 * * * * /bin/bash /opt/apps/gestion-demandes-acces/bin/send-reminders-cron.sh
```

Journaux d'exécution : `var/log/workflow-reminders.log`


## Pour reconnecter github si besoin

### Refaire un token si expiré

TOKEN_GITHUB_A_REMPLACER (date d'expiration du dernier token au 15 novembre)

```bash
git config --global --unset-all credential.helper
git config --global credential.helper store

unset GIT_ASKPASS SSH_ASKPASS VSCODE_GIT_ASKPASS_NODE VSCODE_GIT_ASKPASS_MAIN VSCODE_GIT_IPC_HANDLE

git remote -v

git push -u origin master

martinv96
```

### Supprime les fichiers de l'index de Git (mais les garde sur le disque)

```bash
git rm --cached code.md
git rm --cached codes.md
```

Puis commit la modification :

```bash
git add .
git commit -m "commentaire"
git push
```
