<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Service\FieldValue;
use Psr\Log\LoggerInterface;

/**
 * Sets a field on the record to a fixed value (e.g. advance a status). Writes
 * the value column directly — it does NOT go through the record update path, so
 * it never re-fires automations (no loops). Relation/file/auto fields are not
 * supported (they live in join tables / are derived).
 *
 * action_config: { field: machineName, value: mixed }.
 */
class SetFieldAction implements IAction {

	private const UNSUPPORTED = ['relation', 'file', 'auto', 'computed'];

	public function __construct(
		private FieldMapper $fieldMapper,
		private RecordValueMapper $valueMapper,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'set_field';
	}

	public function isDeferred(): bool {
		// Must run inline so the stored value is immediately authoritative; it
		// writes the value column directly, so it never re-fires automations.
		return false;
	}

	public function run(ActionContext $context): void {
		$machineName = trim((string)($context->config['field'] ?? ''));
		if ($machineName === '') {
			return;
		}
		$field = null;
		foreach ($this->fieldMapper->findByRegister($context->registerId) as $f) {
			if ($f->getMachineName() === $machineName) {
				$field = $f;
				break;
			}
		}
		if ($field === null || in_array($field->getType(), self::UNSUPPORTED, true)) {
			return;
		}

		try {
			$this->valueMapper->deleteForRecordField($context->recordId, $field->getId());
			$stored = FieldValue::toStorage($field->getType(), $context->config['value'] ?? null);
			if ($stored['column'] !== '') {
				$this->valueMapper->insertValue($context->recordId, $field->getId(), $stored['column'], $stored['value']);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms set-field action failed', ['exception' => $e]);
		}
	}
}
