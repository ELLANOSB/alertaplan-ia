# AlertaPlan IA · imagen para Railway (o cualquier servicio que ejecute Docker)
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers \
 && printf "upload_max_filesize=6M\npost_max_size=8M\nmemory_limit=256M\nexpose_php=Off\ndate.timezone=America/Lima\n" > /usr/local/etc/php/conf.d/alertaplan.ini

ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && printf '<Directory /app/public>\n  Options -Indexes\n  AllowOverride None\n  Require all granted\n</Directory>\nServerName localhost\nServerTokens Prod\nServerSignature Off\nHeader always set X-Content-Type-Options "nosniff"\nHeader always set X-Frame-Options "SAMEORIGIN"\n' > /etc/apache2/conf-available/zz-alertaplan.conf \
 && a2enconf zz-alertaplan

WORKDIR /app
COPY . /app
RUN mkdir -p /app/storage && chown -R www-data:www-data /app/storage && chmod +x /app/docker/entrypoint.sh

# APP_ROLE=web (por defecto) sirve la aplicación; APP_ROLE=bot ejecuta el bot de Telegram
CMD ["/app/docker/entrypoint.sh"]
