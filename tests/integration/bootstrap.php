<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Integration-test bootstrap: runs INSIDE a real Nextcloud so the mappers
// exercise the actual migrated schema through a real IDBConnection (the Db
// seam from the coverage PRD). Unlike the standalone unit bootstrap, this one
// boots the server, so it only works when the app lives under a Nextcloud
// checkout (apps/ or custom_apps/) — i.e. the CI integration job, or a local
// `nextcloud:*-apache` container with the app mounted.

// The app's own autoloader first: phpunit + the autoload-dev PSR-4 that maps
// OCA\Dataforms\Tests\ → tests/ (Nextcloud only autoloads lib/).
require_once __DIR__ . '/../../vendor/autoload.php';

// Boot Nextcloud. From <nc>/(apps|custom_apps)/dataforms/tests/integration the
// server root is four levels up.
$base = dirname(__DIR__, 4) . '/lib/base.php';
if (!is_file($base)) {
	fwrite(STDERR, "Nextcloud base.php not found at $base — integration tests must run inside a Nextcloud instance.\n");
	exit(1);
}
require_once $base;

\OC_App::loadApp('dataforms');
