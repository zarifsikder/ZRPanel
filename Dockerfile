FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev \
    unzip mariadb-client cron nsd4 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip intl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/user_data \
    && chown -R www-data:www-data /var/www/html/user_data \
    && mkdir -p /etc/nsd/zones \
    && chown -R www-data:www-data /etc/nsd

RUN echo "*/5 * * * * www-data php /var/www/html/cron_runner.php > /dev/null 2>&1" > /etc/cron.d/zenpanel
RUN echo "*/2 * * * * www-data php /var/www/html/dns_sync.php > /dev/null 2>&1" > /etc/cron.d/dns-sync

EXPOSE 80 53/udp 53/tcp

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
