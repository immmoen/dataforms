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

		return [
			'records' => $dtos,
			'total' => $this->recordMapper->countByRegister($registerId, $search),
			'fields' => array_map(static fn (Field $f) => $f->jsonSerialize(), $fields),
		];
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $recordId): array {
		$record = $this->findReadable($userId, $recordId);
		$fields = $this->fieldMapper->findByRegister($record->getRegisterId());
		$values = $this->valueMapper->findByRecordIds([$record->getId()]);
		return $this->toDto($record, $fields, $values[$record->getId()] ?? []);
	}

	/**
	 * @param array<string,mixed> $values machineName => value
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, array $values): array {
		$this->registerService->findManageable($userId, $registerId);
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
		$this->registerService->findManageable($userId, $record->getRegisterId());
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
		$this->registerService->findManageable($userId, $record->getRegisterId());
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
}
