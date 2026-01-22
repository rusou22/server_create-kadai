FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev zip unzip \
    libjpeg62-turbo-dev libpng-dev libwebp-dev \
  && docker-php-ext-configure gd --with-jpeg --with-webp \
  && docker-php-ext-install pdo_mysql mysqli gd \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
