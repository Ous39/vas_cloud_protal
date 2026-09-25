FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mysqli pdo pdo_mysql curl && a2enmod rewrite headers expires
COPY app/public/ /var/www/html/
COPY app/config/ /var/www/config/
COPY app/lib/ /var/www/lib/
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/wait-for-db-and-start.php /usr/local/bin/wait-for-db-and-start.php
RUN chown -R www-data:www-data /var/www/html /var/www/config /var/www/lib \
    && find /var/www/html /var/www/config /var/www/lib -type f -exec chmod 0644 {} \; \
    && find /var/www/html /var/www/config /var/www/lib -type d -exec chmod 0755 {} \;
# Production PHP settings (no errors shown to users, no PHP version header) and no Apache version banner
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i "s/^expose_php = On/expose_php = Off/" "$PHP_INI_DIR/php.ini" \
    && echo "ServerTokens Prod" > /etc/apache2/conf-available/zz-hardening.conf \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/zz-hardening.conf \
    && echo "ServerName localhost" >> /etc/apache2/conf-available/zz-hardening.conf \
    && a2enconf zz-hardening
WORKDIR /var/www/html
CMD ["php", "/usr/local/bin/wait-for-db-and-start.php"]
