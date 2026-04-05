FROM php:8.2-apache

# Instalar dependencias del sistema y extensiones PHP necesarias
RUN apt-get update && apt-get install -y \
        libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite para que funcione el .htaccess
RUN a2enmod rewrite

# Permitir AllowOverride All en el directorio raíz
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copiar código fuente
COPY . /var/www/html/

# Crear directorios de datos con permisos correctos para www-data
RUN mkdir -p /var/www/html/data/img_cache \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

EXPOSE 80
