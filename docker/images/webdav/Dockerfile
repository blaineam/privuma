ARG PHP_IMAGE=php:7-apache
ARG COMPOSER_IMAGE=composer:1

# Build the php code
FROM ${COMPOSER_IMAGE} AS build

WORKDIR /build

ADD web/composer.json .
ADD web/composer.lock .
ADD web/privumaFile.php .
ADD web/privumaDir.php .
ADD web/server.php .

RUN composer global require hirak/prestissimo \
        && composer install

# Build the server image
FROM ${PHP_IMAGE}

# Add envsubst
RUN apt-get update && apt-get install -y \
            gettext-base \
        && rm -rf /var/lib/apt/lists/*

WORKDIR /var/webdav
COPY --from=build /build/ /var/webdav

COPY scripts/entrypoint.sh /entrypoint.sh
COPY scripts/install.sh /install.sh
COPY config/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY config/php/php.ini /php.ini.template

RUN a2enmod rewrite
RUN mkdir /scripts.d

# Default webdav user (CHANGE THIS!)
ENV WEBDAV_USERNAME=admin
ENV WEBDAV_PASSWORD=admin
ENV SKIP_PERMISSIONS_FIX=no
ENV PHP_UPLOAD_MAX_FILESIZE=64M
ENV PHP_POST_MAX_SIZE=64M
ENV PHP_INI="; set the PHP_INI env var to put things here."

WORKDIR /var/webdav
VOLUME /var/webdav/public
VOLUME /var/webdav/data

ENTRYPOINT [ "/entrypoint.sh" ]
CMD [ "apache2-foreground" ]
