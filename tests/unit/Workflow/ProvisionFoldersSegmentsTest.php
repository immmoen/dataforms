<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ProvisionFoldersAction;
use OCA\Dataforms\Workflow\ValueInterpolator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionObject;

/**
 * The folder-path builder: basePath + interpolated template segments, with the
 * fix that a non-empty template segment which resolves to EMPTY (a blank/auto/
 * misspelled field) skips the whole template instead of silently collapsing the
 * tree — the bug behind "{number}/EDPB guidelines" becoming just "EDPB guidelines".
 */
class ProvisionFoldersSegmentsTest extends TestCase {

	/**
	 * @param array<string,mixed> $values
	 * @return string[]|null
	 */
	private function resolve(string $base, string $template, array $values) {
		$action = (new \ReflectionClass(ProvisionFoldersAction::class))->newInstanceWithoutConstructor();
		$ref = new ReflectionObject($action);
		$ip = $ref->getProperty('interpolator');
		$ip->setAccessible(true);
		$ip->setValue($action, new ValueInterpolator());
		$lg = $ref->getProperty('logger');
		$lg->setAccessible(true);
		$lg->setValue($action, $this->createMock(LoggerInterface::class));

		$m = $ref->getMethod('resolveSegments');
		$m->setAccessible(true);
		$ctx = new ActionContext(1, 1, 'u', 'a', [], []);
		return $m->invoke($action, $base, $template, $values, $ctx);
	}

	public function testTokensResolveIntoNestedSegments(): void {
		// The user's exact case, now with {number} (a sequence auto field) provided.
		$this->assertSame(
			['02. Expert subgroup', '7', 'EDPB guidelines'],
			$this->resolve('02. Expert subgroup', '{number}/EDPB guidelines', ['number' => '7']),
		);
	}

	public function testEmptyPlaceholderSkipsTheWholeTemplate(): void {
		// {number} missing → the template is skipped (null), NOT collapsed to "Docs".
		$this->assertNull($this->resolve('', '{number}/Docs', []));
		$this->assertNull($this->resolve('Base', '{missing}/Sub', ['other' => 'x']));
	}

	public function testNoBasePath(): void {
		$this->assertSame(['Reports', '2026'], $this->resolve('', 'Reports/{year}', ['year' => '2026']));
	}

	public function testDateFormatToken(): void {
		$this->assertSame(['20260701'], $this->resolve('', '{d|Ymd}', ['d' => '2026-07-01']));
	}

	public function testSlashInsideAValueStaysOneSegment(): void {
		// pathSafeValue confines a value to a single segment ("/" -> space).
		$this->assertSame(['a b'], $this->resolve('', '{name}', ['name' => 'a/b']));
	}

	public function testLiteralOnlyTemplate(): void {
		$this->assertSame(['Clients', 'Contracts'], $this->resolve('Clients', 'Contracts', []));
	}
}
