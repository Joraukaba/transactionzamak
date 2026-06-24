FROM php:8.2-apache

COPY . /var/www/html/

RUN mv /var/www/html/transactions.php /var/www/html/index.php

EXPOSE 80