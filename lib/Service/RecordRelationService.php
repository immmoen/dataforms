<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Relation handling for records: persisting relation targets (with the
 * cross-register integrity gate), resolving raw target ids into {id,label}
 * objects on read (behind a per-register read gate), and enforcing the
 * on-delete policy (block / cascade / null) when a referenced record is removed.
 *
 * Extracted from RecordService (#8) with behaviour unchanged. The write and read
 * gates together close the cross-register information-disclosure hole: a Write
 * user can neither point a relation at an arbitrary foreign record nor read back
 * a label from a register they cannot access.
 */
class RecordRelationService {
	public function __construct(
		private RecordRefMapper $refMapper,
		private RecordMapper $recordMapper,
		private FieldMapper $fieldMapper,
		private RecordValueMapper $valueMapper,
		private RegisterService $registerService,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Persist a relation field's referenced record ids into the join table.
	 *
	 * Each target id is validated to be a live record in the field's configured
	 * target register before it is stored — this stops an API caller from
	 * pointing a relation at an arbitrary record in another register (a
	 * data-integrity hole and the write half of the cross-register leak that
	 * resolveRelations() guards on read).
	 *
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 * @throws ValidationException on an invalid target id
	 */
	public function storeRefs(int $recordId, array $fields, array $values): void {
		foreach ($fields as $field) {
			if ($field->getType() !== 'relation') {
				continue;
			}
			$this->refMapper->deleteForRecordField($recordId, $field->getId());
			$value = $values[$field->getMachineName()] ?? null;
			$list = is_array($value) && !isset($value['id']) ? $value : ($value === null || $value === '' ? [] : [$value]);

			// Candidate target ids, de-duplicated, order preserved.
			$candidates = [];
			foreach ($list as $item) {
				$targetId = is_array($item) ? (int)($item['id'] ?? 0) : (int)$item;
				if ($targetId > 0 && !in_array($targetId, $candidates, true)) {
					$candidates[] = $targetId;
				}
			}
			if ($candidates === []) {
				continue;
			}

			// Integrity gate: reject any id that is not a live record in the
			// field's configured target register.
			$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
			$targetReg = (int)($cfg['targetRegisterId'] ?? 0);
			if ($targetReg > 0) {
				$valid = $this->recordMapper->existingIdsInRegister($candidates, $targetReg);
				$invalid = array_values(array_diff($candidates, $valid));
				if ($invalid !== []) {
					throw new ValidationException(
						$field->getLabel() . ': invalid relation target' . (count($invalid) > 1 ? 's' : '') . ' ' . implode(', ', $invalid),
						[$field->getMachineName() => 'Invalid relation target']
					);
				}
			}

			$position = 0;
			foreach ($candidates as $targetId) {
				$this->refMapper->insertRef($recordId, $field->getId(), $targetId, $position++);
			}
		}
	}

	/**
	 * Replace raw relation target ids in DTOs with {id, label} objects.
	 *
	 * The display label is only resolved when the viewing user can read the
	 * relation's target register; otherwise the value is anonymised to a bare
	 * "#id" placeholder. Without this gate a Write user could store an arbitrary
	 * target id and read back a display-field value from a register they have no
	 * access to (cross-register information disclosure).
	 *
	 * @param Field[] $fields
	 * @param array<int,array<string,mixed>> $dtos
	 * @return array<int,array<string,mixed>>
	 */
	public function resolveRelations(string $userId, array $fields, array $dtos): array {
		$relationFields = array_filter($fields, static fn (Field $f) => $f->getType() === 'relation');
		if (count($relationFields) === 0) {
			return $dtos;
		}
		$recordIds = array_map(static fn (array $d): int => (int)$d['id'], $dtos);
		$refsByRecord = $this->refMapper->findByRecordIds($recordIds);

		// Collect target ids per relation field to batch-resolve labels.
		$targetIdsByField = [];
		foreach ($refsByRecord as $rows) {
			foreach ($rows as $row) {
				$targetIdsByField[$row['field_id']][] = $row['target_record_id'];
			}
		}
		$readableCache = [];
		$labelsByField = [];
		foreach ($relationFields as $field) {
			$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
			$targetReg = (int)($cfg['targetRegisterId'] ?? 0);
			$ids = array_values(array_unique($targetIdsByField[$field->getId()] ?? []));
			// Read gate: resolve labels only for target registers this user can read.
			$labelsByField[$field->getId()] = ($targetReg > 0 && $this->canRead($userId, $targetReg, $readableCache))
				? $this->labelsForRecords($targetReg, $ids, (string)($cfg['displayField'] ?? ''))
				: [];
		}

		foreach ($dtos as &$dto) {
			$rows = $refsByRecord[$dto['id']] ?? [];
			foreach ($relationFields as $field) {
				$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
				$multiple = (bool)($cfg['multiple'] ?? false);
				$items = [];
				foreach ($rows as $row) {
					if ($row['field_id'] === $field->getId()) {
						$tid = $row['target_record_id'];
						$items[] = ['id' => $tid, 'label' => $labelsByField[$field->getId()][$tid] ?? ('#' . $tid)];
					}
				}
				// Single relation returns one object (or null); multi returns a list.
				$dto['values'][$field->getMachineName()] = $multiple ? $items : ($items[0] ?? null);
			}
		}
		unset($dto);
		return $dtos;
	}

	/**
	 * Apply each relation field's on-delete policy to references pointing at the
	 * record being deleted: block (refuse), cascade (soft-delete the referencing
	 * records) or null (drop the reference).
	 *
	 * @throws ValidationException when a 'block' policy forbids the deletion.
	 */
	public function enforceReferentialIntegrity(int $targetRecordId): void {
		$refs = $this->refMapper->findReferencingTarget($targetRecordId);
		if (count($refs) === 0) {
			return;
		}
		$byField = [];
		foreach ($refs as $ref) {
			$byField[$ref['field_id']][] = $ref['record_id'];
		}
		foreach ($byField as $fieldId => $referencingRecordIds) {
			try {
				$cfg = json_decode($this->fieldMapper->find($fieldId)->getConfig() ?? '{}', true) ?: [];
			} catch (DoesNotExistException) {
				$cfg = [];
			}
			$policy = $cfg['onDelete'] ?? 'null';
			if ($policy === 'block') {
				throw new ValidationException('This record is referenced by other records and cannot be deleted');
			}
			if ($policy === 'cascade') {
				$now = $this->time->getTime();
				foreach (array_unique($referencingRecordIds) as $rid) {
					try {
						$ref = $this->recordMapper->find($rid);
						$ref->setDeletedAt($now);
						$this->recordMapper->update($ref);
						$this->refMapper->deleteForRecord($rid);
					} catch (DoesNotExistException) {
						// already gone
					}
				}
			}
			// null + cascade both drop the dangling references to the target.
			$this->refMapper->deleteRefsToTarget($targetRecordId, (int)$fieldId);
		}
	}

	/**
	 * Whether the user may read a register, memoised per call within $cache so a
	 * batch of relations to the same target register costs one permission check.
	 *
	 * @param array<int,bool> $cache registerId => readable
	 */
	private function canRead(string $userId, int $registerId, array &$cache): bool {
		if (array_key_exists($registerId, $cache)) {
			return $cache[$registerId];
		}
		try {
			$this->registerService->find($userId, $registerId); // throws if not readable
			return $cache[$registerId] = true;
		} catch (\Throwable) {
			return $cache[$registerId] = false;
		}
	}

	/**
	 * Resolve display labels for a set of records in a register. Public because
	 * the relation-options picker (RecordService::options) reuses it.
	 *
	 * @param int[] $recordIds
	 * @return array<int,string> recordId => label
	 */
	public function labelsForRecords(int $registerId, array $recordIds, string $displayField): array {
		if (count($recordIds) === 0) {
			return [];
		}
		$targetFields = $this->fieldMapper->findByRegister($registerId);
		$display = null;
		foreach ($targetFields as $f) {
			if ($displayField !== '' && $f->getMachineName() === $displayField) {
				$display = $f;
				break;
			}
		}
		if ($display === null) {
			foreach ($targetFields as $f) {
				if (in_array($f->getType(), ['text', 'longtext', 'email', 'select'], true)) {
					$display = $f;
					break;
				}
			}
			if ($display === null) {
				$display = $targetFields[0] ?? null;
			}
		}
		$out = [];
		foreach ($recordIds as $id) {
			$out[$id] = '#' . $id;
		}
		if ($display === null) {
			return $out;
		}
		$valuesByRecord = $this->valueMapper->findByRecordIds($recordIds);
		foreach ($recordIds as $id) {
			foreach (($valuesByRecord[$id] ?? []) as $row) {
				if ((int)$row['field_id'] === $display->getId()) {
					$v = FieldValue::fromStorage($display->getType(), $row);
					if ($v !== null && $v !== '') {
						$out[$id] = is_array($v) ? implode(', ', $v) : (string)$v;
					}
					break;
				}
			}
		}
		return $out;
	}
}
