<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\AppInfo;

use OCA\Dataforms\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for Phase 0: the app id constant is stable. Real service-wiring
 * tests are added alongside the services in later phases.
 */
class ApplicationTest extends TestCase {
	public function testAppIdIsStable(): void {
		self::assertSame('dataforms', Application::APP_ID);
	}
}
