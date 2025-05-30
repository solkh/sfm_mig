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
RUN composer install --no-scripts --no-autoloader

# Copy the rest of the application
COPY . /app/

# Generate optimized autoloader
RUN composer dump-autoload --optimize

# Command to run when container starts
CMD ["php", "-a"]
