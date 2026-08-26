FROM composer:2

RUN docker-php-ext-install pdo_mysql
