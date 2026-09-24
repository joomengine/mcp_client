# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine AS runtime

# The official CLI image already provides cURL, JSON, fileinfo and POSIX.
RUN docker-php-ext-install -j"$(nproc)" pcntl \
	&& addgroup -g 10001 mcp \
	&& adduser -D -H -u 10001 -G mcp mcp
WORKDIR /app

FROM runtime AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache unzip
COPY composer.json composer.lock ./
COPY src/ src/
COPY bin/ bin/
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
		--no-plugins --no-scripts --classmap-authoritative \
	&& composer check-platform-reqs --no-dev

FROM runtime AS client
COPY --from=dependencies /app /app
COPY LICENSE README.md ./
USER 10001:10001
ENTRYPOINT ["php", "/app/bin/joomengine-mcp"]
CMD ["connect"]
