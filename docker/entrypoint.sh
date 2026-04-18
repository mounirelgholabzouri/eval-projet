#!/bin/bash
set -e

echo "⏳ Attente de MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4', '${DB_USER}', '${DB_PASS}');" 2>/dev/null; do
    sleep 2
done
echo "✅ MySQL prêt."

if [ -f /var/www/html/composer.json ] && [ ! -d /var/www/html/vendor ]; then
    echo "📦 Installation des dépendances Composer..."
    cd /var/www/html && composer install --no-interaction --optimize-autoloader
fi

echo "🚀 Démarrage Apache..."
exec apache2-foreground
