<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\FieldValidator;
use OCA\Dataforms\Service\RecordComputationService;
use OCA\Dataforms\Service\RuleService;
use PHPUnit\Framework\TestCase;

/**
 * RecordComputationService at its own seam (#8 split): the authoritative
 * rule + computed-value pass. The rule engine and field validator are real
 * instances; only RuleService (rule source) and the uniqueness mapper are
 * mocked. The full behaviour matrix is also exercised through RecordService in
 * the characterization suite; these lock the unit independently.
 */
class RecordComputationServiceTest extends TestCase {
	/** @param array<int,array<string,mixed>> $rules */
	private function service(array $rules = []): RecordComputationService {
		$ruleService = $this->createMock(RuleService::class);
		$ruleService->method('definitionsForRegister')->willReturn($rules);
		$expr = new ExpressionEvaluator();
		$valueMapper = $this->createMock(RecordValueMapper::class);
		return new RecordComputationService($ruleService, new RuleEvaluator($expr), $expr, new FieldValidator($valueMapper));
	}

	/** @param array<string,mixed>|null $config */
	private function field(string $type, string $machineName, ?array $config = null, bool $mandatory = false): Field {
		$f = new Field();
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setLabel(ucfirst($machineName));
		$f->setConfig($config === null ? null : json_encode($config));
		$f->setMandatory($mandatory);
		$f->setIsUnique(false);
		return $f;
	}

	public function testEvaluatesComputedFieldAndReturnsValues(): void {
		$fields = [$this->field('number', 'qty'), $this->field('number', 'price'), $this->field('computed', 'total', ['expression' => 'qty * price'])];
		$out = $this->service()->validateAndCompute(5, $fields, ['qty' => 3, 'price' => 4, 'total' => 999], 0);
		$this->assertSame(12.0, $out['total']); // submitted 999 overridden by the expression (numeric result)
		$this->assertSame(3, $out['qty']);
	}

	public function testHiddenFieldIsNulled(): void {
		$rules = [['effect' => 'show', 'target' => 'extra', 'conditions' => ['logic' => 'and', 'rules' => [['field' => 'kind', 'op' => 'eq', 'value' => 'x']]]]];
		$fields = [$this->field('text', 'kind'), $this->field('text', 'extra')];
		$out = $this->service($rules)->validateAndCompute(5, $fields, ['kind' => 'y', 'extra' => 'leak'], 0);
		$this->assertNull($out['extra'], 'a field hidden by an unmet show-rule is nulled');
	}

	public function testThrowsOnFieldConfigValidationFailure(): void {
		$fields = [$this->field('email', 'contact')];
		$this->expectException(ValidationException::class);
		$this->service()->validateAndCompute(5, $fields, ['contact' => 'not-an-email'], 0);
	}

	public function testRuleErrorTakesPrecedenceOverFieldError(): void {
		// A required-but-empty field yields a rule error; assert it surfaces.
		$fields = [$this->field('text', 'title', null, true)];
		try {
			$this->service()->validateAndCompute(5, $fields, ['title' => ''], 0);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('This field is required', $e->getErrors()['title']);
		}
	}
}
