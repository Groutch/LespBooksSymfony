FROM php:8.4-apache

# Aligne www-data sur l'utilisateur hote : le code, var/log et public/uploads sont montes.
ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl opcache pdo_mysql zip gd \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

# Cree avant le montage : Docker reprend ces droits pour initialiser le volume de cache.
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log \
    && chown -R www-data:www-data /var/www/html/var

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/app.ini"
COPY docker/php/apache.conf /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite headers

WORKDIR /var/www/html
