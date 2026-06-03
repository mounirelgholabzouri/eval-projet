# Base image with PHP and Apache
FROM php:8.3-apache

# Install runtime dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    libonig-dev \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring opcache gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Configure Apache
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Enable Apache modules
RUN a2enmod rewrite headers

# Copy entrypoint script
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP dependencies (optionnel : l'entrypoint retentera si nécessaire)
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1 || \
    echo "⚠️  composer install partiel — sera complété au démarrage via entrypoint.sh"

# Security: Set proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod 755 /var/www/html && \
    find /var/www/html -type f -exec chmod 644 {} \; && \
    find /var/www/html -type d -exec chmod 755 {} \;

# Create session directory
RUN mkdir -p /var/lib/php/sessions && \
    chown www-data:www-data /var/lib/php/sessions && \
    chmod 750 /var/lib/php/sessions

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
