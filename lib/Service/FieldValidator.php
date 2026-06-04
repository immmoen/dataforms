<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\RecordValueMapper;

/**
 * Server-side enforcement of a field's own configuration (type format, range,
 * length, option membership, uniqueness). This runs in addition to the rule
 * engine's custom validations, so client-supplied data is always type-safe and
 * within bounds before it is persisted.
 */
class FieldValidator {
	public function __construct(
		private RecordValueMapper $valueMapper,
	) {
	}

	/**
	 * @param Field[] $fields
	 * @param array<string,mixed> $values machineName => value
	 * @param int $excludeRecordId record being updated (0 for new)
	 * @return array<string,string> machineName => error message
	 */
	public function validate(array $fields, array $values, int $excludeRecordId = 0): array {
		$errors = [];
		foreach ($fields as $field) {
			$value = $values[$field->getMachineName()] ?? null;
			if ($this->isEmpty($value)) {
				continue; // emptiness/required handled by the rule engine
			}
			$config = json_decode($field->getConfig() ?? '{}', true) ?: [];
			$error = $this->validateOne($field, $value, $config, $excludeRecordId);
			if ($error !== null) {
				$errors[$field->getMachineName()] = $error;
			}
		}
		return $errors;
	}

	/**
	 * @param mixed $value
	 * @param array<string,mixed> $config
	 */
	private function validateOne(Field $field, $value, array $config, int $excludeRecordId): ?string {
		switch ($field->getType()) {
			case 'text':
			case 'longtext':
				if (isset($config['maxLength']) && mb_strlen((string)$value) > (int)$config['maxLength']) {
					return 'Must be at most ' . (int)$config['maxLength'] . ' characters';
				}
				break;
			case 'number':
			case 'currency':
			case 'percentage':
				if (!is_numeric($value)) {
					return 'Must be a number';
				}
				$n = (float)$value;
				if (isset($config['min']) && $config['min'] !== '' && $n < (float)$config['min']) {
					return 'Must be at least ' . $config['min'];
				}
				if (isset($config['max']) && $config['max'] !== '' && $n > (float)$config['max']) {
					return 'Must be at most ' . $config['max'];
				}
				break;
			case 'email':
				if (filter_var((string)$value, FILTER_VALIDATE_EMAIL) === false) {
					return 'Enter a valid email address';
				}
				break;
			case 'url':
				if (filter_var((string)$value, FILTER_VALIDATE_URL) === false) {
					return 'Enter a valid URL';
				}
				break;
			case 'select':
				if ($this->optionsMiss($config, [(string)$value])) {
					return 'Choose one of the allowed options';
				}
				break;
			case 'multiselect':
				if (is_array($value) && $this->optionsMiss($config, array_map('strval', $value))) {
					return 'Choose only from the allowed options';
				}
				break;
		}

		if ($field->getIsUnique()) {
			$payload = FieldValue::toStorage($field->getType(), $value);
			if ($payload['column'] !== ''
				&& $this->valueMapper->valueExistsForField($field->getId(), $payload['column'], $payload['value'], $excludeRecordId)) {
				return 'This value must be unique; another record already uses it';
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $config
	 * @param string[] $picked
	 */
	private function optionsMiss(array $config, array $picked): bool {
		$options = $config['options'] ?? [];
		if (count($options) === 0 || !empty($config['allowOther'])) {
			return false;
		}
		foreach ($picked as $p) {
			if (!in_array($p, $options, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $value
	 */
	private function isEmpty($value): bool {
		return $value === null || $value === '' || (is_array($value) && count($value) === 0);
	}
}
