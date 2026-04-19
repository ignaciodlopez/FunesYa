FROM php:8.2-apache

ENV TZ=America/Argentina/Buenos_Aires

# Instalar dependencias del sistema y extensiones PHP necesarias
RUN apt-get update && apt-get install -y \
        libsqlite3-dev \
        cron \
    && docker-php-ext-install pdo pdo_sqlite \
    && printf "date.timezone=%s\n" "$TZ" > /usr/local/etc/php/conf.d/timezone.ini \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite para que funcione el .htaccess
RUN a2enmod rewrite

# Cambiar DocumentRoot a public/ (separa el código público de los archivos privados)
RUN sed -i 's|DocumentRoot /var/www/html$|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# Permitir AllowOverride All en el directorio raíz (necesario para .htaccess en public/)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copiar código fuente
COPY . /var/www/html/

# Crear directorios de datos con permisos correctos para www-data
RUN mkdir -p /var/www/html/data/img_cache \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

# Asegurar que public/ exista y sea accesible
RUN chown -R www-data:www-data /var/www/html/public

# Cron job: ejecuta el aggregator cada 2 minutos como www-data
# El script ya tiene flock() para evitar ejecuciones simultáneas
RUN echo "*/2 * * * * www-data /usr/local/bin/php /var/www/html/scripts/run_aggregator.php >> /var/www/html/data/aggregator.log 2>&1" \
        > /etc/cron.d/funesya \
    && chmod 0644 /etc/cron.d/funesya

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
CMD ["/usr/local/bin/docker-entrypoint.sh"]
