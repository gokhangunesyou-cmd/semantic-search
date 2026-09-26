FROM composer:2.9.5 AS composer
FROM php:8.3.30-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install pdo_pgsql intl mbstring dom \
    && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app/search-api
COPY search-api/composer.* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY search-api/ .
COPY infrastructure/ /app/infrastructure/
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var && chown -R www-data:www-data var && chmod 2775 var \
    && printf '<VirtualHost *:80>\nDocumentRoot /app/search-api/public\n<Directory /app/search-api/public>\nAllowOverride None\nRequire all granted\nFallbackResource /index.php\n</Directory>\n</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerLimit 4\nStartServers 2\nMinSpareServers 1\nMaxSpareServers 2\nMaxRequestWorkers 4\n' > /etc/apache2/conf-enabled/poc-workers.conf
EXPOSE 80
