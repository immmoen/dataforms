<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Service\FieldValue;
use PHPUnit\Framework\TestCase;

/**
 * Pure coercion tests for the EAV value mapping — which typed column each field
 * type uses, and round-tripping values to/from storage.
 */
class FieldValueTest extends TestCase {
	private function row(array $overrides): array {
		return array_merge([
			'value_string' => null,
			'value_number' => null,
			'value_datetime' => null,
			'value_bool' => null,
			'value_file_id' => null,
			'value_ref_record_id' => null,
		], $overrides);
	}

	public function testColumnMapping(): void {
		self::assertSame('value_string', FieldValue::column('text'));
		self::assertSame('value_string', FieldValue::column('multiselect'));
		self::assertSame('value_number', FieldValue::column('currency'));
		self::assertSame('value_bool', FieldValue::column('boolean'));
		self::assertSame('value_datetime', FieldValue::column('date'));
		self::assertSame('value_ref_record_id', FieldValue::column('relation'));
		self::assertSame('value_file_id', FieldValue::column('file'));
	}

	public function testEmptyValuesAreSkipped(): void {
		self::assertSame('', FieldValue::toStorage('text', '')['column']);
		self::assertSame('', FieldValue::toStorage('text', null)['column']);
		self::assertSame('', FieldValue::toStorage('multiselect', [])['column']);
	}

	public function testTextRoundTrip(): void {
		$s = FieldValue::toStorage('text', 'hello');
		self::assertSame('value_string', $s['column']);
		self::assertSame('hello', $s['value']);
		self::assertSame('hello', FieldValue::fromStorage('text', $this->row(['value_string' => 'hello'])));
	}

	public function testNumberRoundTrip(): void {
		$s = FieldValue::toStorage('number', '3.5');
		self::assertSame('value_number', $s['column']);
		self::assertSame(3.5, $s['value']);
		self::assertSame(3.5, FieldValue::fromStorage('number', $this->row(['value_number' => '3.5'])));
	}

	public function testBooleanCoercion(): void {
		self::assertSame(1, FieldValue::toStorage('boolean', 'yes')['value']);
		self::assertSame(0, FieldValue::toStorage('boolean', 'no')['value']);
		self::assertSame(1, FieldValue::toStorage('boolean', true)['value']);
		self::assertTrue(FieldValue::fromStorage('boolean', $this->row(['value_bool' => 1])));
		self::assertFalse(FieldValue::fromStorage('boolean', $this->row(['value_bool' => 0])));
	}

	public function testDateRoundTrip(): void {
		$s = FieldValue::toStorage('date', '2026-06-03');
		self::assertSame('value_datetime', $s['column']);
		self::assertIsInt($s['value']);
		self::assertSame('2026-06-03', FieldValue::fromStorage('date', $this->row(['value_datetime' => $s['value']])));
	}

	public function testMultiselectRoundTrip(): void {
		$s = FieldValue::toStorage('multiselect', ['a', 'b']);
		self::assertSame('value_string', $s['column']);
		self::assertSame(['a', 'b'], FieldValue::fromStorage('multiselect', $this->row(['value_string' => $s['value']])));
	}

	public function testRelationStoresId(): void {
		self::assertSame(5, FieldValue::toStorage('relation', ['id' => 5, 'label' => 'x'])['value']);
		self::assertSame(5, FieldValue::toStorage('relation', 5)['value']);
		self::assertSame(5, FieldValue::fromStorage('relation', $this->row(['value_ref_record_id' => 5])));
	}

	public function testFileStoresId(): void {
		self::assertSame(20, FieldValue::toStorage('file', ['id' => 20, 'name' => 'r.md'])['value']);
		self::assertSame('value_file_id', FieldValue::toStorage('file', 20)['column']);
		self::assertSame(20, FieldValue::fromStorage('file', $this->row(['value_file_id' => 20])));
	}
}
