#!/bin/sh
# Assemble a clean, installable dataforms/ tree and tar it into
# build/dataforms.tar.gz — a make-free alternative to `make appstore`.
#
# Run on any Unix host (or inside the Nextcloud container) AFTER building the
# frontend (`npm ci && npm run build`). The app has no runtime composer deps —
# Nextcloud autoloads lib/ — so no vendor/ is shipped.
#
#   sh scripts/package.sh
#
set -e
cd "$(dirname "$0")/.."

rm -rf /tmp/df_pkg
mkdir -p /tmp/df_pkg/dataforms

rsync -a \
  --exclude=/.git --exclude=/.github --exclude=/.tx \
  --exclude=/node_modules --exclude=/src --exclude=/tests \
  --exclude=/vendor --exclude=/build --exclude=/scripts \
  --exclude=/.editorconfig --exclude=/.eslintrc.cjs --exclude=/.gitattributes \
  --exclude=/.gitignore --exclude=/.npmrc --exclude=/.php-cs-fixer.dist.php \
  --exclude=/composer.json --exclude=/composer.lock --exclude=/krankerl.toml \
  --exclude=/Makefile --exclude=/package.json --exclude=/package-lock.json \
  --exclude=/playwright.config.js --exclude=/psalm.xml --exclude=/stylelint.config.cjs \
  --exclude=/tsconfig.json --exclude=/vite.config.js --exclude=/vitest.config.js \
  --exclude='*.docx' --exclude='*.map' --exclude='*.cache' --exclude='*.log' \
  ./ /tmp/df_pkg/dataforms/

mkdir -p build
tar -czf build/dataforms.tar.gz -C /tmp/df_pkg dataforms
rm -rf /tmp/df_pkg

echo "Built build/dataforms.tar.gz"
ls -lh build/dataforms.tar.gz
echo "--- sanity (no dev dirs) ---"
tar -tzf build/dataforms.tar.gz | grep -E 'dataforms/(src|tests|vendor|node_modules)/' >/dev/null \
  && echo 'WARNING: dev dirs leaked' || echo 'clean: no src/tests/vendor/node_modules'
