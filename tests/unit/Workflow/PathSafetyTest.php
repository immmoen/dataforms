<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\PathSafety;
use PHPUnit\Framework\TestCase;

/**
 * PathSafety: the confinement boundary for the file-touching actions — no
 * traversal, no separators, no reserved/bidi names, bounded depth.
 */
class PathSafetyTest extends TestCase {
	public function testDropsTraversalSeparatorsAndReservedNames(): void {
		$this->assertSame(['etc', 'passwd'], PathSafety::safeSegments('../../etc/passwd'));
		$this->assertSame(['a', 'b'], PathSafety::safeSegments('a/./b')); // "." dropped
		$this->assertSame(['ok'], PathSafety::safeSegments('con/ok')); // CON reserved → dropped
		$this->assertSame([], PathSafety::safeSegments('..')); // pure traversal → nothing
	}

	public function testStripsIllegalCharsAndBidiAndCapsDepth(): void {
		$this->assertSame(['ab'], PathSafety::safeSegments('a<b>'));
		$deep = implode('/', array_fill(0, 30, 'x'));
		$this->assertCount(PathSafety::MAX_DEPTH, PathSafety::safeSegments($deep));
		// A custom, smaller depth cap is honoured.
		$this->assertCount(2, PathSafety::safeSegments($deep, 2));
	}

	public function testPathSafeValueStaysWithinASegment(): void {
		$this->assertSame('a b', PathSafety::pathSafeValue('a/b'));
		$this->assertSame('a b', PathSafety::pathSafeValue('a\\b'));
		$this->assertSame('cl ean', PathSafety::pathSafeValue("cl\x00ean")); // NUL → space
		$this->assertSame('clean', PathSafety::pathSafeValue("cl\x01ean")); // control char stripped
	}
}
