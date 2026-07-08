FROM php:8.3-fpm AS php
ARG TIMEZONE=UTC

RUN apt-get update && apt-get install -y \
    openssl \
    git \
    unzip \
    default-mysql-client \
    procps \
    telnet \
    net-tools \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN rm -rf /home/user \
        && mkdir /home/user \
        && chmod 777 /home/user


# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer --version

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_HOME /tmp

# Set timezone
RUN ln -snf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime && echo ${TIMEZONE} > /etc/timezone
RUN printf '[PHP]\ndate.timezone = "%s"\n', ${TIMEZONE} > /usr/local/etc/php/conf.d/tzone.ini
RUN "date"

FROM php AS php-development
# install xdebug
RUN pecl install xdebug
RUN docker-php-ext-enable xdebug
RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "display_startup_errors = On" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "display_errors = On" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
# RUN echo "xdebug.remote_enable=1" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "xdebug.idekey=\"PHPSTORM\"" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "xdebug.client_port=9005" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

FROM php AS php-testing
