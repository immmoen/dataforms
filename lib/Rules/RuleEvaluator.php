<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Rules;

/**
 * Evaluates a register's conditional rules against a record's values.
 *
 * This is the AUTHORITATIVE (server-side) interpreter. The JS module in
 * src/rules/engine.js mirrors it for live UX; both read the same rule JSON so
 * they cannot diverge. No dynamic code execution — see ExpressionEvaluator.
 *
 * A rule:
 *   {
 *     effect: 'show'|'require'|'set_value'|'validate'|'compute',
 *     target: '<machineName>',
 *     conditions: { logic: 'and'|'or', rules: [ {field, op, value} ] },
 *     value: <any>,            // set_value
 *     expression: '<expr>',    // compute
 *     validation: { kind, pattern|min|max|expression, message }
 *   }
 */
class RuleEvaluator {
	public function __construct(
		private ExpressionEvaluator $expr,
	) {
	}

	/**
	 * @param array<int,array{machineName:string,type:string,mandatory?:bool}> $fields
	 * @param array<int,array<string,mixed>> $rules
	 * @param array<string,mixed> $values
	 * @return array{values:array<string,mixed>,visible:array<string,bool>,required:array<string,bool>,errors:array<string,string>}
	 */
	public function evaluate(array $fields, array $rules, array $values): array {
		$visible = [];
		$required = [];
		$errors = [];
		$hasShowRule = [];

		foreach ($fields as $f) {
			$mn = $f['machineName'];
			$visible[$mn] = true;
			$required[$mn] = !empty($f['mandatory']);
		}

		// 1) Computed fields first, so their values feed later conditions.
		foreach ($rules as $rule) {
			if (($rule['effect'] ?? '') === 'compute' && !empty($rule['expression'])) {
				try {
					$values[$rule['target']] = $this->expr->evaluate((string)$rule['expression'], $values);
				} catch (ExpressionException) {
					$values[$rule['target']] = null;
				}
			}
		}

		// 2) Visibility: a field referenced by a show-rule is hidden unless a
		// matching rule's conditions are met.
		foreach ($rules as $rule) {
			if (($rule['effect'] ?? '') === 'show') {
				$target = $rule['target'];
				if (!isset($hasShowRule[$target])) {
					$hasShowRule[$target] = true;
					$visible[$target] = false;
				}
				if ($this->matches($rule['conditions'] ?? null, $values)) {
					$visible[$target] = true;
				}
			}
		}

		// 3) set_value (suggest a default when empty and conditions hold).
		foreach ($rules as $rule) {
			if (($rule['effect'] ?? '') === 'set_value' && $this->matches($rule['conditions'] ?? null, $values)) {
				$target = $rule['target'];
				if (($values[$target] ?? '') === '' || ($values[$target] ?? null) === null) {
					$values[$target] = $rule['value'] ?? null;
				}
			}
		}

		// 4) require.
		foreach ($rules as $rule) {
			if (($rule['effect'] ?? '') === 'require' && $this->matches($rule['conditions'] ?? null, $values)) {
				$required[$rule['target']] = true;
			}
		}

		// 5) Required-but-empty errors (only for visible fields).
		foreach ($fields as $f) {
			$mn = $f['machineName'];
			if (($visible[$mn] ?? true) && ($required[$mn] ?? false) && $this->isEmpty($values[$mn] ?? null)) {
				$errors[$mn] = 'This field is required';
			}
		}

		// 6) Custom validations (skip hidden fields).
		foreach ($rules as $rule) {
			if (($rule['effect'] ?? '') !== 'validate') {
				continue;
			}
			$target = $rule['target'];
			if (!($visible[$target] ?? true) || isset($errors[$target])) {
				continue;
			}
			if (!$this->matches($rule['conditions'] ?? null, $values)) {
				continue;
			}
			$message = $this->runValidation($rule['validation'] ?? [], $values[$target] ?? null, $values);
			if ($message !== null) {
				$errors[$target] = $message;
			}
		}

		return [
			'values' => $values,
			'visible' => $visible,
			'required' => $required,
			'errors' => $errors,
		];
	}

	/**
	 * @param array<string,mixed>|null $conditions
	 * @param array<string,mixed> $values
	 */
	public function matches(?array $conditions, array $values): bool {
		if ($conditions === null || empty($conditions['rules'])) {
			return true;
		}
		$logic = strtolower((string)($conditions['logic'] ?? 'and'));
		$results = [];
		foreach ($conditions['rules'] as $cond) {
			$results[] = $this->testCondition($cond, $values);
		}
		if ($logic === 'or') {
			return in_array(true, $results, true);
		}
		return !in_array(false, $results, true);
	}

	/**
	 * @param array<string,mixed> $cond
	 * @param array<string,mixed> $values
	 */
	private function testCondition(array $cond, array $values): bool {
		$left = $values[$cond['field'] ?? ''] ?? null;
		$op = (string)($cond['op'] ?? 'eq');
		$right = $cond['value'] ?? null;

		switch ($op) {
			case 'eq': return $this->looseEq($left, $right);
			case 'neq': return !$this->looseEq($left, $right);
			case 'gt': return (float)$left > (float)$right;
			case 'lt': return (float)$left < (float)$right;
			case 'gte': return (float)$left >= (float)$right;
			case 'lte': return (float)$left <= (float)$right;
			case 'contains': return str_contains($this->s($left), $this->s($right));
			case 'in': return is_array($right) && in_array($left, $right, false);
			case 'isEmpty': return $this->isEmpty($left);
			case 'isNotEmpty': return !$this->isEmpty($left);
			case 'matches':
				$pattern = $this->s($right);
				return $pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $this->s($left)) === 1;
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $validation
	 * @param mixed $value
	 * @param array<string,mixed> $values
	 * @return string|null error message, or null when valid
	 */
	private function runValidation(array $validation, $value, array $values): ?string {
		$kind = (string)($validation['kind'] ?? '');
		$message = (string)($validation['message'] ?? 'Invalid value');

		if ($this->isEmpty($value)) {
			return null; // empties handled by the required check
		}

		switch ($kind) {
			case 'regex':
				$pattern = (string)($validation['pattern'] ?? '');
				if ($pattern === '') {
					return null;
				}
				return @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $this->s($value)) === 1 ? null : $message;
			case 'range':
				$n = (float)$value;
				if (isset($validation['min']) && $validation['min'] !== '' && $n < (float)$validation['min']) {
					return $message;
				}
				if (isset($validation['max']) && $validation['max'] !== '' && $n > (float)$validation['max']) {
					return $message;
				}
				return null;
			case 'expression':
				try {
					$ok = $this->expr->evaluate((string)($validation['expression'] ?? 'true'), $values);
				} catch (ExpressionException) {
					return $message;
				}
				return $this->truthy($ok) ? null : $message;
		}
		return null;
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 */
	private function looseEq($a, $b): bool {
		if (is_numeric($a) && is_numeric($b)) {
			return (float)$a === (float)$b;
		}
		return $this->s($a) === $this->s($b);
	}

	/**
	 * @param mixed $v
	 */
	private function isEmpty($v): bool {
		return $v === null || $v === '' || (is_array($v) && count($v) === 0);
	}

	/**
	 * @param mixed $v
	 */
	private function truthy($v): bool {
		return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0 && $v !== '0';
	}

	/**
	 * @param mixed $v
	 */
	private function s($v): string {
		if (is_bool($v)) {
			return $v ? 'true' : 'false';
		}
		if (is_array($v)) {
			return implode(',', $v);
		}
		return (string)($v ?? '');
	}
}
