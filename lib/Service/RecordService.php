<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;

/**
 * Records and their EAV values. All validation and computed-field evaluation
 * happens here on the server (authoritative); the JS engine is only for live
 * UX. Values arrive keyed by field machine name.
 */
class RecordService {
	public function __construct(
		private RecordMapper $recordMapper,
		private RecordValueMapper $valueMapper,
		private FieldMapper $fieldMapper,
		private RegisterService $registerService,
		private RuleService $ruleService,
		private RuleEvaluator $evaluator,
		private IRootFolder $rootFolder,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array{records:array<int,array<string,mixed>>,total:int,fields:array<int,array<string,mixed>>}
	 * @throws NotFoundException
	 */
	public function list(string $userId, int $registerId, int $limit, int $offset, string $sort, string $direction, string $search): array {
		$this->registerService->find($userId, $registerId);
		$fields = $this->fieldMapper->findByRegister($registerId);
		$records = $this->recordMapper->findByRegister($registerId, $limit, $offset, $sort, $direction, $search);

		$ids = array_map(static fn (Record $r) => $r->getId(), $records);
		$valuesByRecord = $this->valueMapper->findByRecordIds($ids);

		$dtos = [];
		foreach ($records as $record) {
			$dtos[] = $this->toDto($record, $fields, $valuesByRecord[$record->getId()] ?? []);
		}
		$dtos = $this->resolveRelations($fields, $dtos);
		$dtos = $this->resolveFiles($fields, $dtos, $userId);

		return [
			'records' => $dtos,
			'total' => $this->recordMapper->countByRegister($registerId, $search),
			'fields' => array_map(static fn (Field $f) => $f->jsonSerialize(), $fields),
		];
	}

	/**
	 * Pickable options (id + label) for a relation target register.
	 *
	 * @return array<int,array{id:int,label:string}>
	 * @throws NotFoundException
	 */
	public function options(string $userId, int $registerId, string $displayField, string $search): array {
		$this->registerService->find($userId, $registerId); // read gate
		$records = $this->recordMapper->findByRegister($registerId, 50, 0, 'updated', 'DESC', $search);
		$ids = array_map(static fn (Record $r) => $r->getId(), $records);
		$labels = $this->labelsForRecords($registerId, $ids, $displayField);
		$out = [];
		foreach ($records as $record) {
			$out[] = ['id' => $record->getId(), 'label' => $labels[$record->getId()] ?? ('#' . $record->getId())];
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $recordId): array {
		$record = $this->findReadable($userId, $recordId);
		$fields = $this->fieldMapper->findByRegister($record->getRegisterId());
		$values = $this->valueMapper->findByRecordIds([$record->getId()]);
		$dto = $this->toDto($record, $fields, $values[$record->getId()] ?? []);
		$dto = $this->resolveRelations($fields, [$dto])[0];
		return $this->resolveFiles($fields, [$dto], $userId)[0];
	}

	/**
	 * @param array<string,mixed> $values machineName => value
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, array $values): array {
		$this->registerService->findWritable($userId, $registerId);
		$fields = $this->fieldMapper->findByRegister($registerId);
		$values = $this->validateAndCompute($registerId, $fields, $values);

		$now = $this->time->getTime();
		$record = new Record();
		$record->setRegisterId($registerId);
		$record->setOwner($userId);
		$record->setCreatedBy($userId);
		$record->setCreated($now);
		$record->setUpdated($now);
		$record = $this->recordMapper->insert($record);

		$this->storeValues($record->getId(), $fields, $values);
		return $this->toDto($record, $fields, $this->valueMapper->findByRecordIds([$record->getId()])[$record->getId()] ?? []);
	}

	/**
	 * @param array<string,mixed> $values
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function update(string $userId, int $recordId, array $values): array {
		$record = $this->findReadable($userId, $recordId);
		$this->registerService->findWritable($userId, $record->getRegisterId());
		$fields = $this->fieldMapper->findByRegister($record->getRegisterId());
		$values = $this->validateAndCompute($record->getRegisterId(), $fields, $values);

		$record->setUpdated($this->time->getTime());
		$this->recordMapper->update($record);

		$this->valueMapper->deleteByRecord($record->getId());
		$this->storeValues($record->getId(), $fields, $values);
		return $this->toDto($record, $fields, $this->valueMapper->findByRecordIds([$record->getId()])[$record->getId()] ?? []);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $recordId): void {
		$record = $this->findReadable($userId, $recordId);
		$this->registerService->findWritable($userId, $record->getRegisterId());
		$record->setDeletedAt($this->time->getTime());
		$this->recordMapper->update($record);
	}

	// ---- helpers ---------------------------------------------------------

	private function findReadable(string $userId, int $recordId): Record {
		try {
			$record = $this->recordMapper->find($recordId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Record not found');
		}
		$this->registerService->find($userId, $record->getRegisterId()); // read gate
		return $record;
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
	private function validateAndCompute(int $registerId, array $fields, array $values): array {
		$fieldDefs = array_map(static fn (Field $f) => [
			'machineName' => $f->getMachineName(),
			'type' => $f->getType(),
			'mandatory' => (bool)$f->getMandatory(),
		], $fields);

		$rules = $this->ruleService->definitionsForRegister($registerId);
		$result = $this->evaluator->evaluate($fieldDefs, $rules, $values);

		if (count($result['errors']) > 0) {
			throw new ValidationException('Validation failed', $result['errors']);
		}
		// A hidden field's value must not be persisted (authoritative).
		foreach ($result['visible'] as $machineName => $visible) {
			if (!$visible) {
				$result['values'][$machineName] = null;
			}
		}
		return $result['values'];
	}

	/**
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 */
	private function storeValues(int $recordId, array $fields, array $values): void {
		foreach ($fields as $field) {
			$logical = $values[$field->getMachineName()] ?? null;
			$payload = FieldValue::toStorage($field->getType(), $logical);
			if ($payload['column'] !== '') {
				$this->valueMapper->insertValue($recordId, $field->getId(), $payload['column'], $payload['value']);
			}
		}
	}

	/**
	 * @param Field[] $fields
	 * @param array<int,array<string,mixed>> $valueRows
	 * @return array<string,mixed>
	 */
	private function toDto(Record $record, array $fields, array $valueRows): array {
		$byFieldId = [];
		foreach ($valueRows as $row) {
			$byFieldId[(int)$row['field_id']] = $row;
		}
		$values = [];
		foreach ($fields as $field) {
			$row = $byFieldId[$field->getId()] ?? null;
			$values[$field->getMachineName()] = $row === null ? null : FieldValue::fromStorage($field->getType(), $row);
		}
		return [
			'id' => $record->getId(),
			'registerId' => $record->getRegisterId(),
			'createdBy' => $record->getCreatedBy(),
			'created' => $record->getCreated(),
			'updated' => $record->getUpdated(),
			'values' => $values,
		];
	}

	/**
	 * Replace raw relation target ids in DTOs with {id, label} objects.
	 *
	 * @param Field[] $fields
	 * @param array<int,array<string,mixed>> $dtos
	 * @return array<int,array<string,mixed>>
	 */
	private function resolveRelations(array $fields, array $dtos): array {
		foreach ($fields as $field) {
			if ($field->getType() !== 'relation') {
				continue;
			}
			$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
			$targetReg = (int)($cfg['targetRegisterId'] ?? 0);
			$mn = $field->getMachineName();
			if ($targetReg <= 0) {
				continue;
			}
			$ids = [];
			foreach ($dtos as $dto) {
				$v = $dto['values'][$mn] ?? null;
				if (is_int($v) && $v > 0) {
					$ids[] = $v;
				}
			}
			$labels = $this->labelsForRecords($targetReg, array_values(array_unique($ids)), (string)($cfg['displayField'] ?? ''));
			foreach ($dtos as &$dto) {
				$v = $dto['values'][$mn] ?? null;
				$dto['values'][$mn] = (is_int($v) && $v > 0)
					? ['id' => $v, 'label' => $labels[$v] ?? ('#' . $v)]
					: null;
			}
			unset($dto);
		}
		return $dtos;
	}

	/**
	 * Replace raw file ids in DTOs with {id, name} resolved via the Files API
	 * (referenced by id, never stored as a blob). Inaccessible files degrade to
	 * a placeholder name.
	 *
	 * @param Field[] $fields
	 * @param array<int,array<string,mixed>> $dtos
	 * @return array<int,array<string,mixed>>
	 */
	private function resolveFiles(array $fields, array $dtos, string $userId): array {
		$fileFields = array_filter($fields, static fn (Field $f) => $f->getType() === 'file');
		if (count($fileFields) === 0) {
			return $dtos;
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($fileFields as $field) {
			$mn = $field->getMachineName();
			foreach ($dtos as &$dto) {
				$id = $dto['values'][$mn] ?? null;
				if (!is_int($id) || $id <= 0) {
					$dto['values'][$mn] = null;
					continue;
				}
				$name = null;
				try {
					$nodes = $userFolder->getById($id);
					if (count($nodes) > 0) {
						$name = $nodes[0]->getName();
					}
				} catch (\Throwable) {
					// fall through to placeholder
				}
				$dto['values'][$mn] = ['id' => $id, 'name' => $name ?? ('file #' . $id)];
			}
			unset($dto);
		}
		return $dtos;
	}

	/**
	 * Resolve display labels for a set of records in a register.
	 *
	 * @param int[] $recordIds
	 * @return array<int,string> recordId => label
	 */
	private function labelsForRecords(int $registerId, array $recordIds, string $displayField): array {
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
