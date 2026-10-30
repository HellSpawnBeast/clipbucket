# ClipBucket dev runtime: Apache + mod_php 7.4. The app targets PHP 5-7.x and
# relies on old behaviour (split(), zero dates), so it cannot run on PHP 8.
# Bullseye is archived, hence the archive.debian.org sources.
FROM php:7.4-apache-bullseye

RUN printf 'deb http://archive.debian.org/debian bullseye main\ndeb http://archive.debian.org/debian-security bullseye-security main\n' \
        > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/*.list \
    && apt-get -o Acquire::Check-Valid-Until=false update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mysqli zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-clipbucket.conf /etc/apache2/conf-enabled/zz-clipbucket.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-clipbucket.ini
COPY docker/install.php /usr/local/bin/cb-install

WORKDIR /var/www/html
