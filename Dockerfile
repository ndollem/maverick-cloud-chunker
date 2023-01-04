FROM php:8.2.0-apache

WORKDIR /srv/

# NOTE: The entrypoint "/start", which starts up NGINX and PHP-FPM,
# is configured by creating a `.googleconfig/app_start.json` file with the
# contents:
#
#     {"entrypointContents": "CUSTOM_ENTRYPOINT"}
#
# We configure it to use the `router.php` file included in this package.
RUN mkdir .googleconfig && \
    echo '{"entrypointContents": "serve vendor/bin/router.php"}' > .googleconfig/app_start.json

# Install unzip utility and libs needed by zip PHP extension 
RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libzip-dev \
    unzip
RUN docker-php-ext-install zip

# Copy over composer files and run "composer install"
COPY composer.* ./
COPY --from=composer:latest /usr/bin/composer /usr/local/bin
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN set -eux
RUN composer install --no-dev

# Copy over all application files
COPY . .
