FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql mbstring curl \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/wildlife-sentinel/
RUN printf '%s\n' 'DocumentRoot /var/www/html/wildlife-sentinel' '<Directory /var/www/html/wildlife-sentinel>' 'AllowOverride All' 'Require all granted' '</Directory>' 'Alias /wildlife-sentinel /var/www/html/wildlife-sentinel' '<Directory /var/www/html/wildlife-sentinel/demo>' 'Require all denied' '</Directory>' > /etc/apache2/conf-available/wildlife.conf \
    && sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/wildlife-sentinel#' /etc/apache2/sites-available/000-default.conf && a2enconf wildlife \
    && mkdir -p /var/www/html/wildlife-sentinel/uploads \
    && chown -R www-data:www-data /var/www/html/wildlife-sentinel/uploads
EXPOSE 80
CMD ["sh", "/var/www/html/wildlife-sentinel/database/start.sh"]
