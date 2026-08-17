lorsque le push sera réaliser, ne pas oublier de créer un cron avec :

crontab -e

puis de sélectionner un éditeur et d'ajouter la ligne : 

0 8 * * * APP_ENV=prod /usr/bin/php /opt/apps/gestion-demandes-acces/bin/console app:workflow:send-reminders >> /opt/apps/gestion-demandes-acces/var/log/workflow-reminders.log 2>&1