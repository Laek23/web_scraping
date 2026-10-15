FROM php:8.2-cli-alpine

# Install system dependencies & PostgreSQL dev libraries
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libxml2-dev \
    curl \
    git \
    bash \
    icu-dev

# Install PHP extensions required by Laravel & PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql pgsql bcmath opcache zip xml ctype intl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Ensure storage and bootstrap/cache permissions
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Install composer production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
