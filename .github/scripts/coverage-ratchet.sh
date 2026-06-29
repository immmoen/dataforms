#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Global coverage ratchet. Compares freshly-measured global line coverage
# (backend = PHPUnit text report, frontend = Vitest json-summary) against the
# committed floor in .github/coverage-baseline.json. FAILS the build on any
# regression, and raises the floor when coverage improves. The floor can only
# ever go up. When it is raised, sets the step output `ratchet_raised=true` so
# the workflow can best-effort push the new baseline back to main.
#
# Usage: coverage-ratchet.sh [php-coverage.txt] [js-coverage-summary.json]
set -euo pipefail

BASELINE=.github/coverage-baseline.json
PHP_TXT=${1:-coverage.txt}
JS_SUMMARY=${2:-coverage/coverage-summary.json}

# Backend: the PHPUnit text report's "Lines:  NN.NN% (x/y)" summary line.
php_cur=$(grep -oE 'Lines:[[:space:]]+[0-9.]+' "$PHP_TXT" | grep -oE '[0-9.]+' | head -1)
# Frontend: Vitest's coverage-summary.json total line percentage.
js_cur=$(jq -r '.total.lines.pct' "$JS_SUMMARY")
php_base=$(jq -r '.php' "$BASELINE")
js_base=$(jq -r '.js' "$BASELINE")

echo "Backend  lines: floor ${php_base}%  current ${php_cur}%"
echo "Frontend lines: floor ${js_base}%  current ${js_cur}%"

# Enforce: a drop below the floor (beyond float noise) fails the build.
regressed=$(python3 - "$php_cur" "$php_base" "$js_cur" "$js_base" <<'PY'
import sys
php_cur, php_base, js_cur, js_base = map(float, sys.argv[1:5])
eps = 0.01
bad = []
if php_cur + eps < php_base:
    bad.append(f"backend {php_cur}% < floor {php_base}%")
if js_cur + eps < js_base:
    bad.append(f"frontend {js_cur}% < floor {js_base}%")
print("; ".join(bad))
PY
)
if [ -n "$regressed" ]; then
  echo "::error::Coverage ratchet regression: $regressed"
  exit 1
fi

# Raise: only when coverage strictly improved on at least one side.
changed=$(python3 -c "print('yes' if (float('$php_cur') > float('$php_base') or float('$js_cur') > float('$js_base')) else 'no')")
if [ "$changed" = "yes" ]; then
  new_php=$(python3 -c "print(max(float('$php_cur'), float('$php_base')))")
  new_js=$(python3 -c "print(max(float('$js_cur'), float('$js_base')))")
  comment=$(jq -r '._comment' "$BASELINE")
  jq -n --arg c "$comment" --argjson php "$new_php" --argjson js "$new_js" \
    '{_comment: $c, php: $php, js: $js}' > "$BASELINE"
  echo "Ratchet raised: php ${php_base}% -> ${new_php}%, js ${js_base}% -> ${new_js}%"
  echo "ratchet_raised=true" >> "${GITHUB_OUTPUT:-/dev/null}"
else
  echo "Ratchet unchanged (no improvement)."
  echo "ratchet_raised=false" >> "${GITHUB_OUTPUT:-/dev/null}"
fi
