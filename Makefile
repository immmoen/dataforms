# SPDX-License-Identifier: AGPL-3.0-or-later
# Build / package targets for the Dataforms app.

app_name=dataforms
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates

.PHONY: all
all: build

.PHONY: deps
deps:
	composer install
	npm ci

# Production frontend bundle (js/dataforms-main.mjs + css/).
.PHONY: build
build:
	npm run build

.PHONY: lint
lint:
	composer run cs:check
	composer run psalm
	npm run lint
	npm run stylelint

.PHONY: test
test:
	composer run test:unit
	npm run test

# Assemble a clean tree and sign it with the App Store certificate.
# Requires $(cert_dir)/$(app_name).key and .crt issued by Nextcloud.
# Rebuilds the frontend from scratch (rm js css first) so no stale hashed
# chunks from earlier builds leak into the signed tarball.
.PHONY: appstore
appstore:
	rm -rf js css
	npm run build
	rm -rf $(sign_dir)
	mkdir -p $(sign_dir)/$(app_name)
	rsync -a \
		--exclude=/.git \
		--exclude=/.github \
		--exclude=/.tx \
		--exclude=/build \
		--exclude=/node_modules \
		--exclude=/src \
		--exclude=/tests \
		--exclude=/composer.* \
		--exclude=/package*.json \
		--exclude=/*.toml \
		--exclude=/*.cjs \
		--exclude=/*.js \
		--exclude=/*.json \
		--exclude=/.npmrc \
		--exclude=/.gitattributes \
		--exclude=/.editorconfig \
		--exclude=/Makefile \
		--exclude=/psalm.xml \
		--exclude=*.map \
		--exclude=*.docx \
		./ $(sign_dir)/$(app_name)/
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(sign_dir) $(app_name)
	@echo "Sign with: occ integrity:sign-app --path=$(sign_dir)/$(app_name) \\"
	@echo "  --privateKey=$(cert_dir)/$(app_name).key --certificate=$(cert_dir)/$(app_name).crt"

.PHONY: clean
clean:
	rm -rf $(build_dir) js css node_modules vendor
