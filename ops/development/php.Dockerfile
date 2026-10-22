# Official PHP image; no system-wide host installation.
FROM docker.io/library/php:8.5.12-cli@sha256:595a7cbb1e0547336319f228c4dc991c2a199350ab12b1c8fda05f73abd06e6b
RUN php -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);'

# Build context contains packages fetched by host APT from signed official Debian
# indexes and the official PECL source. Build with --network=none. No proxy CA or
# trust configuration is copied into this image.
COPY archives/*.deb /tmp/dependencies/
RUN dpkg -i /tmp/dependencies/*.deb \
    && docker-php-ext-install -j2 pdo_pgsql bcmath intl pcntl zip \
    && rm -rf /tmp/dependencies

COPY xdebug-3.5.3.tgz /tmp/xdebug.tgz
RUN mkdir /tmp/xdebug \
    && tar -xzf /tmp/xdebug.tgz -C /tmp/xdebug --strip-components=1 \
    && cd /tmp/xdebug && phpize && ./configure --enable-xdebug \
    && make -j2 && make install \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/xdebug /tmp/xdebug.tgz
