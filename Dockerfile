FROM php:8.3-apache

ARG SOURCE_URL=https://github.com/hamzaarshad-exe/booknest
LABEL org.opencontainers.image.title="BookNest" \
      org.opencontainers.image.description="Service appointment booking by Softnic Solutions" \
      org.opencontainers.image.authors="Softnic Solutions <https://softnicsolutions.com>" \
      org.opencontainers.image.url="https://softnicsolutions.com" \
      org.opencontainers.image.source="${SOURCE_URL}" \
      org.opencontainers.image.licenses="MIT"

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers \
    && mkdir -p /var/lib/booknest/sessions /usr/local/lib/booknest

COPY src/ /var/www/html/
COPY docker/bootstrap.php docker/healthcheck.php /usr/local/lib/booknest/
COPY docker/entrypoint.sh /usr/local/bin/booknest-entrypoint
COPY docker/apache.conf /etc/apache2/conf-enabled/booknest.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/booknest.ini

RUN chmod 0755 /usr/local/bin/booknest-entrypoint \
    && chown -R www-data:www-data /var/lib/booknest

ENV BOOKNEST_CONFIG_FILE=/var/lib/booknest/config.php
EXPOSE 80
ENTRYPOINT ["booknest-entrypoint"]
CMD ["apache2-foreground"]
