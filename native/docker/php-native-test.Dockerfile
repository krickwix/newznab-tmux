FROM composer:2

RUN docker-php-ext-install pdo_mysql \
    && apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .phpize-deps
