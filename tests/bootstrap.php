<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Composer autoloader for standalone unit tests. Tests that need the full
// Nextcloud server context are run via the server's own test bootstrap in CI.
require_once __DIR__ . '/../vendor/autoload.php';
