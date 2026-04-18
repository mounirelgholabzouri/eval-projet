FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    unzip \
    libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh && a2enmod rewrite

WORKDIR /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
