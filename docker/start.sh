#!/bin/sh
# Démarrage du conteneur sur Render.
set -e
cd /var/www/html

# Apache écoute sur le port imposé par Render.
sed -i "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf

# Aiven MySQL exige une connexion chiffrée : le certificat CA d'Aiven est fourni
# dans la variable DB_CA_CERT (contenu du fichier ca.pem) et écrit ici.
if [ -n "$DB_CA_CERT" ]; then
    printf '%s\n' "$DB_CA_CERT" > /var/www/html/storage/aiven-ca.pem
    export MYSQL_ATTR_SSL_CA=/var/www/html/storage/aiven-ca.pem
fi

# Caches de production + migrations (sans effacer les données).
php artisan config:cache
php artisan route:cache
php artisan view:cache || true
php artisan migrate --force

# Données de démo (comptes admin/vendeur/acheteur/livreur, catégories, produits) :
# mettre SEED_DEMO=true pour UN seul déploiement, puis retirer la variable.
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force || echo "Seed ignoré (données déjà présentes ?)"
fi
php artisan storage:link || true

# Les caches ont été créés par root : Apache (www-data) doit pouvoir les lire et écrire dans storage.
chown -R www-data:www-data storage bootstrap/cache

# Tâches planifiées (commandes expirées, confirmation auto des livraisons) en arrière-plan.
php artisan schedule:work > /dev/stdout 2>&1 &

exec apache2-foreground
