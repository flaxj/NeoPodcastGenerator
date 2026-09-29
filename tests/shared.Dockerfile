FROM php:8.4-apache AS apache
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libxml2-dev \
    && docker-php-ext-install pdo_sqlite dom \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-enabled/neo.conf \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen\nupload_max_filesize=5M\npost_max_size=6M\nmemory_limit=128M\n' > /usr/local/etc/php/conf.d/shared.ini

FROM php:8.4-fpm AS fpm
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libxml2-dev \
    && docker-php-ext-install pdo_sqlite dom \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen\nupload_max_filesize=5M\npost_max_size=6M\nmemory_limit=128M\n' > /usr/local/etc/php/conf.d/shared.ini
