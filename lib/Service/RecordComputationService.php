<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;

/**
 * Rule and computed-value computation for a record's submitted values. Runs the
 * shared rule engine (show/require/set-value/validate), evaluates computed
 * fields server-side, nulls hidden fields, and enforces each visible field's own
 * config — returning the authoritative value map or throwing on validation.
 *
 * Extracted from RecordService (#8) with behaviour unchanged: the JS engine is
 * only for live UX; this is the authoritative server-side pass.
 */
class RecordComputationService {
	public function __construct(
		private RuleService $ruleService,
		private RuleEvaluator $evaluator,
		private ExpressionEvaluator $expr,
		private FieldValidator $fieldValidator,
	) {
	}

	/**
	 * Run the rule engine: compute computed fields, enforce required and
	 * validation. Returns the value map with computed values applied.
	 *
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function validateAndCompute(int $registerId, array $fields, array $values, int $excludeRecordId): array {
		$fieldDefs = array_map(static fn (Field $f) => [
			'machineName' => $f->getMachineName(),
			'type' => $f->getType(),
			'mandatory' => (bool)$f->getMandatory(),
		], $fields);

		$rules = $this->ruleService->definitionsForRegister($registerId);
		$result = $this->evaluator->evaluate($fieldDefs, $rules, $values);

		// A hidden field's value must not be persisted (authoritative).
		foreach ($result['visible'] as $machineName => $visible) {
			if (!$visible) {
				$result['values'][$machineName] = null;
			}
		}

		// Computed field types: evaluate their expression server-side (always,
		// even if hidden) so the stored value is authoritative.
		foreach ($fields as $field) {
			if ($field->getType() === 'computed') {
				$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
				try {
					$result['values'][$field->getMachineName()] = $this->expr->evaluate((string)($cfg['expression'] ?? ''), $result['values']);
				} catch (\Throwable) {
					$result['values'][$field->getMachineName()] = null;
				}
			}
		}

		// Enforce each visible field's own config (format/range/length/options/
		// uniqueness) on top of the rule-driven validations.
		$visibleFields = array_filter(
			$fields,
			static fn (Field $f) => ($result['visible'][$f->getMachineName()] ?? true)
		);
		$fieldErrors = $this->fieldValidator->validate($visibleFields, $result['values'], $excludeRecordId);

		// Rule errors take precedence over generic field-config errors.
		$errors = array_merge($fieldErrors, $result['errors']);
		if (count($errors) > 0) {
			throw new ValidationException('Validation failed', $errors);
		}
		return $result['values'];
	}
}
