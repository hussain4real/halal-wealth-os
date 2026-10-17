# Official PHP image; no system-wide host installation.
FROM docker.io/library/php:8.5.11-cli@sha256:01a109229f4465bc9ef042d9198f09a4d9da7775a825dbd8572dcdbd8756c4d3

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
