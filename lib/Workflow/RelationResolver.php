<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Service\FieldValue;
use OCA\Dataforms\Service\RegisterService;

/**
 * Enriches a record's values with the *scalar fields of its relation targets*,
 * exposed as `{relationField}.{targetField}` keys, so workflow templates can
 * interpolate them — e.g. a meeting's `subgroup` relation to an "ESG Subgroups"
 * register makes `{subgroup.name}` and `{subgroup.code}` available.
 *
 * Security: it resolves a target's fields only when the record **owner** can read
 * the target register (the same read gate as RecordService::resolveRelations),
 * so it can't be used to read fields out of a register the owner has no access
 * to. Relation/file/auto/computed target fields are skipped (only simple scalars
 * are exposed).
 */
class RelationResolver {

	private const SKIP_TYPES = ['relation', 'file', 'auto', 'computed'];

	public function __construct(
		private FieldMapper $fieldMapper,
		private RecordMapper $recordMapper,
		private RecordValueMapper $valueMapper,
		private RegisterService $registerService,
	) {
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	public function enrich(string $ownerId, int $registerId, array $values): array {
		foreach ($this->fieldMapper->findByRegister($registerId) as $field) {
			if ($field->getType() !== 'relation') {
				continue;
			}
			$targetId = $this->targetId($values[$field->getMachineName()] ?? null);
			if ($targetId === null) {
				continue;
			}
			$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
			$targetReg = (int)($cfg['targetRegisterId'] ?? 0);
			if ($targetReg <= 0) {
				continue;
			}
			// Read gate: only resolve targets in registers the owner may read.
			try {
				$this->registerService->find($ownerId, $targetReg);
			} catch (\Throwable) {
				continue;
			}
			try {
				$target = $this->recordMapper->find($targetId);
			} catch (\Throwable) {
				continue;
			}
			if ($target->getRegisterId() !== $targetReg) {
				continue; // integrity: the relation must point into its configured register
			}

			$rows = $this->valueMapper->findByRecordIds([$targetId])[$targetId] ?? [];
			$byFieldId = [];
			foreach ($rows as $row) {
				$byFieldId[(int)$row['field_id']] = $row;
			}
			foreach ($this->fieldMapper->findByRegister($targetReg) as $tf) {
				if (in_array($tf->getType(), self::SKIP_TYPES, true)) {
					continue;
				}
				$row = $byFieldId[$tf->getId()] ?? null;
				$v = $row === null ? '' : FieldValue::fromStorage($tf->getType(), $row);
				$values[$field->getMachineName() . '.' . $tf->getMachineName()]
					= is_array($v) ? implode(', ', $v) : (string)($v ?? '');
			}
		}
		return $values;
	}

	/**
	 * Extract a single target record id from a relation value (an {id} object, a
	 * raw id, or a one-element list of either). Null if none.
	 *
	 * @param mixed $raw
	 */
	private function targetId($raw): ?int {
		if (is_array($raw)) {
			if (isset($raw['id'])) {
				return ((int)$raw['id']) ?: null;
			}
			$first = $raw[0] ?? null;
			if (is_array($first)) {
				return isset($first['id']) ? (((int)$first['id']) ?: null) : null;
			}
			return $first !== null ? (((int)$first) ?: null) : null;
		}
		if (is_numeric($raw)) {
			return ((int)$raw) ?: null;
		}
		return null;
	}
}
