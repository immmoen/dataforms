<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Composer autoloader for standalone unit tests. Tests that need the full
// Nextcloud server context are run via the server's own test bootstrap in CI.
require_once __DIR__ . '/../vendor/autoload.php';

// Stubs for non-public OC\ symbols referenced (but not shipped) by nextcloud/ocp
// — needed so the mock generator can resolve some OCP inheritance chains.
require_once __DIR__ . '/stubs.php';

// nextcloud/ocp ships the public OCP/NCU API as plain files but declares no
// autoload section (it is meant for static analysis), so OCP classes are not
// resolvable at unit-test runtime. Register a test-only autoloader that loads
// the stubs on demand. This affects the test process only — production uses the
// real OCP shipped by the Nextcloud server, and dev deps are excluded from the
// App-Store build (composer install --no-dev).
spl_autoload_register(static function (string $class): void {
	foreach (['OCP', 'NCU'] as $ns) {
		if (str_starts_with($class, $ns . '\\')) {
			$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});
