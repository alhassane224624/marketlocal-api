#!/bin/sh
set -e
cd /var/www/html

# Render impose le port via la variable PORT (10000 par défaut)
PORT="${PORT:-10000}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Certificat SSL de la base Aiven (collé dans la variable MYSQL_SSL_CA_PEM)
if [ -n "$MYSQL_SSL_CA_PEM" ]; then
    printf '%s\n' "$MYSQL_SSL_CA_PEM" > storage/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/var/www/html/storage/mysql-ca.pem
fi

php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true

# Migrations automatiques à chaque déploiement
php artisan migrate --force

# Données de démo : insérées automatiquement uniquement si la base est vide
USERS=$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n 1)
if [ "$USERS" = "0" ]; then
    php artisan db:seed --force
fi

php artisan storage:link 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache

# Planificateur (annulation des commandes expirées) en tâche de fond
php artisan schedule:work &

exec "$@"
