<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\ValueInterpolator;
use PHPUnit\Framework\TestCase;

/**
 * ValueInterpolator: {machineName} placeholder substitution with the optional
 * whitelisted {field|date-format} suffix and {relation.subfield} paths (AUT-16/17/18).
 */
class ValueInterpolatorTest extends TestCase {
	private ValueInterpolator $i;

	protected function setUp(): void {
		$this->i = new ValueInterpolator();
	}

	public function testSubstitutesFieldValuesAndLeavesUnknownTokensEmpty(): void {
		$out = $this->i->interpolate('Hi {name}, ref {ref}, gone {missing}', ['name' => 'Ada', 'ref' => 7]);
		$this->assertSame('Hi Ada, ref 7, gone ', $out);
	}

	public function testFormatsADateTokenWithAWhitelistedFormat(): void {
		$v = ['d' => '2026-07-01', 'dt' => '2026-07-01T09:30'];
		$this->assertSame('2026', $this->i->interpolate('{d|Y}', $v));
		$this->assertSame('20260701', $this->i->interpolate('{d|Ymd}', $v));
		$this->assertSame('01-07-2026', $this->i->interpolate('{d|d-m-Y}', $v));
		$this->assertSame('2026-07-01 09:30', $this->i->interpolate('{dt|Y-m-d H:i}', $v));
	}

	public function testFormatIsIgnoredForNonDatesOrDisallowedFormats(): void {
		// A non-date value: the format is dropped, the raw value used.
		$this->assertSame('hello', $this->i->interpolate('{x|Y}', ['x' => 'hello']));
		// A format with a disallowed character (a letter outside the whitelist) → raw.
		$this->assertSame('2026-07-01', $this->i->interpolate('{d|Yexec}', ['d' => '2026-07-01']));
		// A non-string raw → raw stringified.
		$this->assertSame('5', $this->i->interpolate('{n|Y}', ['n' => 5]));
		// An unparseable date string → raw.
		$this->assertSame('2026-99-99', $this->i->interpolate('{d|Y}', ['d' => '2026-99-99']));
	}

	public function testRelationSubfieldPathAndStringification(): void {
		$out = $this->i->interpolate('{sub.code} / {flag} / {tags}', [
			'sub.code' => 'ESG-1',
			'flag' => true,
			'tags' => ['a', ['label' => 'b'], ['id' => 3]],
		]);
		$this->assertSame('ESG-1 / yes / a, b, 3', $out);
	}

	public function testValueTransformIsApplied(): void {
		$out = $this->i->interpolate('{x}', ['x' => 'a/b'], static fn (string $s): string => str_replace('/', '-', $s));
		$this->assertSame('a-b', $out);
	}
}
