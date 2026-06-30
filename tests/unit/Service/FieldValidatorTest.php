<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Service\FieldValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Server-side field-config enforcement: type format, range, length, option
 * membership and uniqueness — the type-safety net under the rule engine.
 */
class FieldValidatorTest extends TestCase {
	private RecordValueMapper&MockObject $valueMapper;
	private FieldValidator $validator;

	protected function setUp(): void {
		$this->valueMapper = $this->createMock(RecordValueMapper::class);
		$this->validator = new FieldValidator($this->valueMapper);
	}

	private function field(string $type, array $config = [], bool $unique = false, string $name = 'f', int $id = 1): Field {
		$f = new Field();
		$f->setId($id);
		$f->setMachineName($name);
		$f->setType($type);
		$f->setConfig(json_encode($config));
		$f->setIsUnique($unique);
		return $f;
	}

	/** @return array<string,string> */
	private function check(Field $field, $value, int $exclude = 0): array {
		return $this->validator->validate([$field], [$field->getMachineName() => $value], $exclude);
	}

	public function testTextRespectsMaxLength(): void {
		$f = $this->field('text', ['maxLength' => 3]);
		$this->assertArrayHasKey('f', $this->check($f, 'abcd'));
		$this->assertSame([], $this->check($f, 'abc'));
	}

	public function testNumberMustBeNumericAndWithinRange(): void {
		$f = $this->field('number', ['min' => 1, 'max' => 10]);
		$this->assertArrayHasKey('f', $this->check($f, 'x'));
		$this->assertArrayHasKey('f', $this->check($f, 0));
		$this->assertArrayHasKey('f', $this->check($f, 11));
		$this->assertSame([], $this->check($f, 5));
	}

	public function testEmailAndUrlFormat(): void {
		$this->assertArrayHasKey('f', $this->check($this->field('email'), 'nope'));
		$this->assertSame([], $this->check($this->field('email'), 'a@b.com'));
		$this->assertArrayHasKey('f', $this->check($this->field('url'), 'nope'));
		$this->assertSame([], $this->check($this->field('url'), 'https://x.test'));
	}

	public function testSelectAndMultiselectOptionMembership(): void {
		$sel = $this->field('select', ['options' => ['a', 'b']]);
		$this->assertArrayHasKey('f', $this->check($sel, 'c'));
		$this->assertSame([], $this->check($sel, 'a'));

		$multi = $this->field('multiselect', ['options' => ['a', 'b']]);
		$this->assertArrayHasKey('f', $this->check($multi, ['a', 'z']));
		$this->assertSame([], $this->check($multi, ['a', 'b']));
	}

	public function testAllowOtherAndNoOptionsSkipMembership(): void {
		$this->assertSame([], $this->check($this->field('select', ['options' => ['a'], 'allowOther' => true]), 'free'));
		$this->assertSame([], $this->check($this->field('select', ['options' => []]), 'anything'));
	}

	public function testUniquenessRejectsAnExistingValue(): void {
		$f = $this->field('text', [], unique: true);
		$this->valueMapper->expects($this->once())->method('valueExistsForField')
			->with(1, 'value_string', $this->anything(), 7)->willReturn(true);
		$this->assertArrayHasKey('f', $this->check($f, 'dup', 7));
	}

	public function testUniquenessAllowsAFreshValue(): void {
		$f = $this->field('text', [], unique: true);
		$this->valueMapper->method('valueExistsForField')->willReturn(false);
		$this->assertSame([], $this->check($f, 'fresh'));
	}

	public function testEmptyValuesAndDerivedFieldsAreSkipped(): void {
		$this->valueMapper->expects($this->never())->method('valueExistsForField');
		$this->assertSame([], $this->check($this->field('text', [], unique: true), ''));
		$this->assertSame([], $this->check($this->field('text', [], unique: true), null));
		$this->assertSame([], $this->check($this->field('multiselect'), []));
		$this->assertSame([], $this->check($this->field('computed'), 'x'));
		$this->assertSame([], $this->check($this->field('auto'), 'x'));
	}

	public function testAggregatesErrorsByMachineName(): void {
		$errors = $this->validator->validate(
			[$this->field('email', name: 'mail'), $this->field('number', name: 'qty')],
			['mail' => 'bad', 'qty' => 'NaN'],
		);
		$this->assertArrayHasKey('mail', $errors);
		$this->assertArrayHasKey('qty', $errors);
	}
}
