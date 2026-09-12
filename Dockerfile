FROM php:8.2-apache
RUN docker-php-ext-install mysqli pdo pdo_mysql && a2enmod rewrite headers expires
COPY app/public/ /var/www/html/
COPY app/config/ /var/www/config/
COPY app/lib/ /var/www/lib/
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/wait-for-db-and-start.php /usr/local/bin/wait-for-db-and-start.php
RUN chown -R www-data:www-data /var/www/html /var/www/config /var/www/lib \
    && find /var/www/html /var/www/config /var/www/lib -type f -exec chmod 0644 {} \; \
    && find /var/www/html /var/www/config /var/www/lib -type d -exec chmod 0755 {} \;
WORKDIR /var/www/html
CMD ["php", "/usr/local/bin/wait-for-db-and-start.php"]
