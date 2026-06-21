FROM debian:bookworm-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        apache2 \
        libapache2-mod-php \
        php \
        php-mysql \
        php-zip \
        php-mbstring \
        php-xml \
        php-curl \
        php-gd \
        curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]