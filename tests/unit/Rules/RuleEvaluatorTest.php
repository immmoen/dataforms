<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Rules;

use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHP rule evaluator against the shared fixtures
 * (tests/fixtures/rule-cases.json), the same cases the JS engine.spec.js runs,
 * proving the two interpreters agree.
 */
class RuleEvaluatorTest extends TestCase {
	private RuleEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new RuleEvaluator(new ExpressionEvaluator());
	}

	/**
	 * @return array<string,array{0:array<mixed>,1:array<mixed>,2:array<mixed>,3:array<mixed>}>
	 */
	public static function ruleProvider(): array {
		$json = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/rule-cases.json'), true);
		$cases = [];
		foreach ($json['rules'] as $c) {
			$cases[$c['name']] = [$c['fields'], $c['rules'], $c['values'], $c['expect']];
		}
		return $cases;
	}

	/**
	 * @dataProvider ruleProvider
	 * @param array<int,array<string,mixed>> $fields
	 * @param array<int,array<string,mixed>> $rules
	 * @param array<string,mixed> $values
	 * @param array<string,mixed> $expect
	 */
	public function testSharedFixtures(array $fields, array $rules, array $values, array $expect): void {
		$result = $this->evaluator->evaluate($fields, $rules, $values);

		if (isset($expect['values'])) {
			foreach ($expect['values'] as $key => $value) {
				self::assertEqualsWithDelta((float)$value, (float)$result['values'][$key], 0.000001, "value $key");
			}
		}
		if (isset($expect['visible'])) {
			foreach ($expect['visible'] as $key => $value) {
				self::assertSame($value, $result['visible'][$key], "visible $key");
			}
		}
		if (isset($expect['required'])) {
			foreach ($expect['required'] as $key => $value) {
				self::assertSame($value, $result['required'][$key], "required $key");
			}
		}
		if (isset($expect['errors'])) {
			self::assertSame($expect['errors'], $result['errors'], 'errors');
		}
	}

	public function testComputeFeedsConditions(): void {
		$fields = [
			['machineName' => 'a', 'type' => 'number'],
			['machineName' => 'b', 'type' => 'number'],
			['machineName' => 'total', 'type' => 'number'],
			['machineName' => 'note', 'type' => 'text'],
		];
		$rules = [
			['effect' => 'compute', 'target' => 'total', 'expression' => 'a + b'],
			['effect' => 'require', 'target' => 'note', 'conditions' => ['logic' => 'and', 'rules' => [['field' => 'total', 'op' => 'gt', 'value' => 10]]]],
		];
		$result = $this->evaluator->evaluate($fields, $rules, ['a' => 7, 'b' => 5]);
		self::assertSame(12.0, (float)$result['values']['total']);
		self::assertArrayHasKey('note', $result['errors']); // required because total > 10
	}
}
