# Sistema de Consentimientos — imagen de aplicación (PHP 8.2 + Apache)
FROM php:8.2-apache

# Extensiones que usa la app: pdo_mysql, mysqli (la app usa mysqli), zip, gd, mbstring
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev unzip curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd \
    && docker-php-ext-enable mysqli \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Ajustes PHP para subida/firmas/backups
RUN { \
      echo "upload_max_filesize=32M"; \
      echo "post_max_size=32M"; \
      echo "memory_limit=512M"; \
      echo "max_execution_time=180"; \
      echo "date.timezone=America/Bogota"; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# El código de la app (public_html) va al docroot
COPY app/ /var/www/html/

# TCPDF: la app incluye ../assets/lib/tcpdf/tcpdf.php — se instala aquí si no viene en el código
RUN if [ ! -f /var/www/html/assets/lib/tcpdf/tcpdf.php ]; then \
      mkdir -p /tmp/tcpdf && cd /tmp/tcpdf && \
      curl -fsSL -o tcpdf.tar.gz https://github.com/tecnickcom/TCPDF/archive/refs/tags/6.7.5.tar.gz && \
      tar xzf tcpdf.tar.gz && \
      mkdir -p /var/www/html/assets/lib/tcpdf && \
      cp -r TCPDF-6.7.5/* /var/www/html/assets/lib/tcpdf/ && \
      rm -rf /tmp/tcpdf ; \
    fi

# Permisos para carpetas escribibles (uploads, backups) — se montan como volúmenes en runtime
RUN mkdir -p /var/www/html/assets/uploads /var/www/html/admin/backups \
    && chown -R www-data:www-data /var/www/html

# Apache: servir desde /var/www/html y permitir .htaccess
RUN sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf || true

EXPOSE 80
