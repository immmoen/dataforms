# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Dataforms development image — the full toolchain in one container so the
# local gates match CI: PHP 8.3 (the CI coverage version) with pcov and the
# same extension set, Composer, and Node 22.
#
# Build:  docker compose -f docker-compose.dev.yml build
# Use:    docker compose -f docker-compose.dev.yml run --rm dev make test
FROM php:8.3-cli-bookworm

# install-php-extensions resolves all the native libs (libicu, libzip, …) for us.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
		mbstring \
		iconv \
		fileinfo \
		intl \
		pdo_sqlite \
		pdo_mysql \
		pdo_pgsql \
		gd \
		zip \
		bz2 \
		curl \
		pcov

# Composer (pinned major) from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 (the upper bound of the project's supported 20/22 range) + git, unzip
# for Composer/npm source installs.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates curl gnupg git unzip rsync \
	&& mkdir -p /etc/apt/keyrings \
	&& curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
	&& echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
	&& apt-get update \
	&& apt-get install -y --no-install-recommends nodejs \
	&& rm -rf /var/lib/apt/lists/*

# pcov on by default so `composer run test:unit` collects coverage like CI does.
# pcov.directory must be the repo root (mounted at /app) to cover both lib/ and
# src/ — without it pcov auto-detects only /app/src and misses the PHP backend.
RUN printf 'pcov.enabled=1\npcov.directory=/app\n' > /usr/local/etc/php/conf.d/pcov.ini

WORKDIR /app
