Pour vider le cache et reconstruire après modifications :
sudo rm -rf var/cache/* && sudo -u www-data APP_ENV=prod php bin/console cache:warmup

sudo systemctl status nginx php8.3-fpm mysql
sudo systemctl restart nginx php8.3-fpm


cd /opt/apps/gestion-demandes-acces
sudo chown -R www-data:www-data public/assets
sudo chmod -R u+rwX,g+rwX public/assets
sudo -u www-data APP_ENV=prod php bin/console asset-map:compile
sudo -u www-data APP_ENV=prod php bin/console cache:clear
sudo -u www-data APP_ENV=prod php bin/console cache:warmup


Pour la config Mail :

Modifier le fichier: /.env.prod
MAILER_DSN=smtp://no-reply%40leplessistrevise.fr:Leplessis94%2A@mail.infomaniak.com:587
MAILER_FROM=no-reply@leplessistrevise.fr


