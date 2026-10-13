# Image de production de l'API MarketLocal (Laravel) pour Render (offre gratuite).
# Apache + PHP 8.3 : plusieurs requêtes à la fois (contrairement à `php artisan serve`).
FROM php:8.3-apache

# Extensions PHP nécessaires à Laravel + MySQL
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev ca-certificates \
    && docker-php-ext-install pdo_mysql zip intl bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Apache sert le dossier public/ de Laravel
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/marketlocal.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dépendances d'abord (meilleur cache Docker), puis le code
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh

# Render fournit le port dans $PORT (10000 par défaut)
ENV PORT=10000
EXPOSE 10000

CMD ["docker/start.sh"]
