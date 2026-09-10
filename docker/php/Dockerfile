FROM wordpress:php8.3-apache

RUN a2enmod rewrite headers expires \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip msmtp-mta \
    && docker-php-ext-install intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/oneclicktranslation.ini
COPY docker/php/msmtprc /etc/msmtprc
RUN chmod 0644 /etc/msmtprc
