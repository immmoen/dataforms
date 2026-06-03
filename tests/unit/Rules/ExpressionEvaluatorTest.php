<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Rules;

use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\ExpressionException;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PHP expression evaluator against the shared fixtures
 * (tests/fixtures/rule-cases.json) — the same cases the JS suite uses, so both
 * implementations are proven to agree. Also covers the sandbox guarantees.
 */
class ExpressionEvaluatorTest extends TestCase {
	private ExpressionEvaluator $eval;

	protected function setUp(): void {
		$this->eval = new ExpressionEvaluator();
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:mixed}>
	 */
	public static function expressionProvider(): array {
		$json = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/rule-cases.json'), true);
		$cases = [];
		foreach ($json['expression'] as $c) {
			$cases[$c['name']] = [$c['expr'], $c['values'], $c['expect']];
		}
		return $cases;
	}

	/**
	 * @dataProvider expressionProvider
	 * @param array<string,mixed> $values
	 * @param mixed $expected
	 */
	public function testSharedFixtures(string $expr, array $values, $expected): void {
		$result = $this->eval->evaluate($expr, $values);
		if (is_numeric($expected) && !is_string($expected)) {
			self::assertEqualsWithDelta((float)$expected, (float)$result, 0.000001);
		} else {
			self::assertSame($expected, $result);
		}
	}

	public function testRejectsUnknownFunction(): void {
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate('danger(1)', []);
	}

	public function testUndefinedIdentifierIsNull(): void {
		self::assertNull($this->eval->evaluate('window', []));
		self::assertNull($this->eval->evaluate('process', []));
	}

	public function testRejectsMalformedInput(): void {
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate('1 +', []);
	}

	public function testRejectsUnbalancedParentheses(): void {
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate('(1 + 2', []);
	}
}
