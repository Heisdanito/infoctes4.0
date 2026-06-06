FROM php:8.2-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx libonig-dev libzip-dev zip unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring zip \
    && sed -i 's#^listen = .*#listen = 9000#' /usr/local/etc/php-fpm.d/www.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY nginx.conf /etc/nginx/sites-available/default
COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
