Pour vider le cache et reconstruire après modifications :
sudo rm -rf var/cache/* && sudo -u www-data APP_ENV=prod php bin/console cache:warmup

sudo systemctl status nginx php8.3-fpm mysql
sudo systemctl restart nginx php8.3-fpm



Pour la config Mail :

Modifier le fichier: /.env.prod
MAILER_DSN=smtp://no-reply%40leplessistrevise.fr:Leplessis94%2A@mail.infomaniak.com:587
MAILER_FROM=no-reply@leplessistrevise.fr


