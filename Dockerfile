FROM php:8.1-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    zip \
    unzip \
    libzip-dev

# Install PHP extensions
RUN docker-php-ext-install zip mysqli
RUN pecl install mongodb && \
    docker-php-ext-enable mongodb

# Set working directory
WORKDIR /app

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy only composer files first for better layer caching
COPY composer.json composer.lock* /app/

# Install dependencies
RUN composer install --no-scripts

# Copy the rest of the application
COPY . /app/

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Add a health check script
COPY <<'EOF' /app/health.php
<?php
echo "Health check OK\n";
exit(0 );
EOF

# Add an entrypoint script that keeps the container running
COPY <<'EOF' /app/entrypoint.sh
#!/bin/sh
echo "Migration container is ready. Connect to it using 'docker exec -it [container_id] bash'"
echo "Then run: php test-migration.php"
echo "And if tests pass: php mongodb-to-wp-migration.php"
tail -f /dev/null
EOF

RUN chmod +x /app/entrypoint.sh

# Add healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 CMD [ "php", "/app/health.php" ]

# Use the entrypoint script to keep container running
ENTRYPOINT ["/app/entrypoint.sh"]
