Pour vider le cache et reconstruire après modifications :
sudo rm -rf var/cache/* && sudo -u www-data APP_ENV=prod php bin/console cache:warmup