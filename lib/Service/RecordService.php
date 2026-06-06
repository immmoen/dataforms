<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\History;
use OCA\Dataforms\Db\HistoryMapper;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Event\RecordDeletedEvent;
use OCA\Dataforms\Event\RecordUpdatedEvent;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * Records and their EAV values. All validation and computed-field evaluation
 * happens here on the server (authoritative); the JS engine is only for live
 * UX. Values arrive keyed by field machine name.
 */
class RecordService {
	public function __construct(
		private RecordMapper $recordMapper,
		private RecordValueMapper $valueMapper,
		private RecordFileMapper $fileMapper,
		private RecordRefMapper $refMapper,
		private FieldMapper $fieldMapper,
		private RegisterService $registerService,
		private RuleService $ruleService,
		private RuleEvaluator $evaluator,
		private ExpressionEvaluator $expr,
		private FieldValidator $fieldValidator,
		private IRootFolder $rootFolder,
		private ITimeFactory $time,
		private HistoryMapper $historyMapper,
		private IEventDispatcher $eventDispatcher,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{records:array<int,array<string,mixed>>,total:int,fields:array<int,array<string,mixed>>}
	 * @throws NotFoundException
	 */
	/**
	 * @param array<int,array{field:string,op:string,value?:mixed}> $filters
	 * @return array{records:array<int,array<string,mixed>>,total:int,fields:array<int,array<string,mixed>>}
	 * @throws NotFoundException
	 */
	public function list(string $userId, int $registerId, int $limit, int $offset, string $sort, string $direction, string $search, array $filters = []): array {
		$this->registerService->find($userId, $registerId);
		$fields = $this->fieldMapper->findByRegister($registerId);
		$byName = [];
		foreach ($fields as $f) {
			$byName[$f->getMachineName()] = $f;
		}

		$resolvedFilters = $this->resolveFilters($filters, $byName);

		// Auto fields (sequence / created / updated / created-by) aren't stored
		// in value columns, so sort them by the matching record-table column.
		$sortField = null;
		if (isset($byName[$sort]) && $byName[$sort]->getType() === 'auto') {
			$cfg = json_decode($byName[$sort]->getConfig() ?? '{}', true) ?: [];
			$sort = match ($cfg['kind'] ?? 'created_at') {
				'sequence' => 'seq',
				'created_at' => 'created',
				'updated_at' => 'updated',
				'created_by' => 'created_by',
				default => 'seq',
			};
		} elseif (isset($byName[$sort])) {
			$sortField = [
				'column' => FieldValue::column($byName[$sort]->getType()),
				'fieldId' => $byName[$sort]->getId(),
			];
		}

		$records = $this->recordMapper->findByRegister($registerId, $limit, $offset, $sort, $direction, $search, $resolvedFilters, $sortField);

		$ids = array_map(static fn (Record $r) => $r->getId(), $records);
		$valuesByRecord = $this->valueMapper->findByRecordIds($ids);

		$dtos = [];
		foreach ($records as $record) {
			$dtos[] = $this->toDto($record, $fields, $valuesByRecord[$record->getId()] ?? []);
		}
		$dtos = $this->resolveRelations($userId, $fields, $dtos);
		$dtos = $this->resolveFiles($fields, $dtos, $userId);

		return [
			'records' => $dtos,
			'total' => $this->recordMapper->countByRegister($registerId, $search, $resolvedFilters),
			'fields' => array_map(static fn (Field $f) => $f->jsonSerialize(), $fields),
		];
	}

	/**
	 * Resolve client filter criteria (by field machine name) into typed-column
	 * criteria for the mapper.
	 *
	 * @param array<int,array{field:string,op:string,value?:mixed}> $filters
	 * @param array<string,Field> $byName
	 * @return array<int,array{fieldId:int,column:string,op:string,value:mixed}>
	 */
	private function resolveFilters(array $filters, array $byName): array {
		$out = [];
		foreach ($filters as $filter) {
			$name = (string)($filter['field'] ?? '');
			if (!isset($byName[$name])) {
				continue;
			}
			$field = $byName[$name];
			$type = $field->getType();
			if (in_array($type, ['file', 'relation'], true)) {
				continue; // not filterable here
			}
			$op = (string)($filter['op'] ?? 'eq');
			$column = FieldValue::column($type);
			$value = null;
			if (!in_array($op, ['isEmpty', 'isNotEmpty'], true)) {
				$payload = FieldValue::toStorage($type, $filter['value'] ?? null);
				$value = $payload['value'];
			}
			$out[] = ['fieldId' => $field->getId(), 'column' => $column, 'op' => $op, 'value' => $value];
		}
		return $out;
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
		$dto = $this->resolveRelations($userId, $fields, [$dto])[0];
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
		$values = $this->validateAndCompute($registerId, $fields, $values, 0);

		// The record header and its five value/join tables are written as one
		// unit; a mid-write failure must leave nothing behind.
		$record = $this->atomically(fn (): Record => $this->writeNewRecord($userId, $registerId, $fields, $values));

		// Dispatch only after the data is durably committed, so automations
		// (notifications, webhooks) never fire on a half-written or rolled-back row.
		$this->eventDispatcher->dispatchTyped(new RecordCreatedEvent($registerId, $record->getId(), $userId, $values));

		$dto = $this->toDto($record, $fields, $this->valueMapper->findByRecordIds([$record->getId()])[$record->getId()] ?? []);
		$dto = $this->resolveRelations($userId, $fields, [$dto])[0];
		return $this->resolveFiles($fields, [$dto], $userId)[0];
	}

	/**
	 * Bulk-import variant: validates and writes one record WITHOUT opening its
	 * own transaction (the importer wraps the whole batch) and WITHOUT dispatching
	 * record events — a bulk load must never fire per-row automations/webhooks.
	 * The caller is responsible for the write-permission check and the surrounding
	 * transaction.
	 *
	 * @param Field[] $fields pre-loaded register fields
	 * @param array<string,mixed> $values machineName => value
	 * @throws ValidationException
	 */
	public function createForImport(string $userId, int $registerId, array $fields, array $values): void {
		$values = $this->validateAndCompute($registerId, $fields, $values, 0);
		$this->writeNewRecord($userId, $registerId, $fields, $values);
	}

	/**
	 * Insert a new record header plus its value/file/ref rows and the create-history
	 * entry. Pure write — no transaction management, no permission check, no event
	 * dispatch (the callers own those).
	 *
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 */
	private function writeNewRecord(string $userId, int $registerId, array $fields, array $values): Record {
		$now = $this->time->getTime();
		$record = new Record();
		$record->setRegisterId($registerId);
		$record->setOwner($userId);
		$record->setCreatedBy($userId);
		$record->setCreated($now);
		$record->setUpdated($now);
		// Per-register running number (1, 2, 3 …), stable across deletions.
		$record->setSeq($this->recordMapper->maxSeqForRegister($registerId) + 1);
		$record = $this->recordMapper->insert($record);

		$this->storeValues($record->getId(), $fields, $values);
		$this->storeFiles($record->getId(), $fields, $values);
		$this->storeRefs($record->getId(), $fields, $values);
		$this->logHistory($registerId, $record->getId(), $userId, 'create', 'Created record', []);
		return $record;
	}

	/**
	 * @param array<string,mixed> $values
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function update(string $userId, int $recordId, array $values): array {
		$record = $this->findReadable($userId, $recordId);
		$register = $this->registerService->findWritable($userId, $record->getRegisterId());
		$this->requireOwnOrManage($userId, $record, $register);
		$fields = $this->fieldMapper->findByRegister($record->getRegisterId());
		$values = $this->validateAndCompute($record->getRegisterId(), $fields, $values, $record->getId());

		$before = $this->valueSnapshot($record->getId());

		// update() is the dangerous path: it deletes the record's values before
		// re-inserting them, so a failure mid-write would otherwise drop data.
		// Wrap the header update + value replacement + history in one transaction.
		$changed = $this->atomically(function () use ($record, $userId, $fields, $values, $before): array {
			$record->setUpdated($this->time->getTime());
			$this->recordMapper->update($record);

			$this->valueMapper->deleteByRecord($record->getId());
			$this->storeValues($record->getId(), $fields, $values);
			$this->storeFiles($record->getId(), $fields, $values);
			$this->storeRefs($record->getId(), $fields, $values);

			$after = $this->valueSnapshot($record->getId());
			return $this->logUpdate($record->getRegisterId(), $record->getId(), $userId, $fields, $before, $after);
		});

		$this->eventDispatcher->dispatchTyped(new RecordUpdatedEvent($record->getRegisterId(), $record->getId(), $userId, $values, $changed));

		$dto = $this->toDto($record, $fields, $this->valueMapper->findByRecordIds([$record->getId()])[$record->getId()] ?? []);
		$dto = $this->resolveRelations($userId, $fields, [$dto])[0];
		return $this->resolveFiles($fields, [$dto], $userId)[0];
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $recordId): void {
		$record = $this->findReadable($userId, $recordId);
		$register = $this->registerService->findWritable($userId, $record->getRegisterId());
		$this->requireOwnOrManage($userId, $record, $register);

		// Referential-integrity enforcement may cascade-delete referencing records,
		// so it belongs inside the transaction: a 'block' policy throws and rolls
		// back cleanly (nothing written), a 'cascade' soft-deletes atomically.
		$this->atomically(function () use ($record, $recordId, $userId): void {
			$this->enforceReferentialIntegrity($recordId);
			$record->setDeletedAt($this->time->getTime());
			$this->recordMapper->update($record);
			$this->refMapper->deleteForRecord($recordId); // remove this record's outgoing refs
			$this->logHistory($record->getRegisterId(), $recordId, $userId, 'delete', 'Deleted record', []);
		});

		$this->eventDispatcher->dispatchTyped(new RecordDeletedEvent($record->getRegisterId(), $recordId, $userId));
	}

	/**
	 * Run a write closure inside a single DB transaction, committing on success
	 * and rolling back on any throwable (which is re-thrown). Keeps multi-table
	 * record writes atomic so a partial failure can never leave orphaned or
	 * missing value rows.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	private function atomically(callable $fn) {
		$this->db->beginTransaction();
		try {
			$result = $fn();
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			try {
				$this->db->rollBack();
			} catch (\Throwable) {
				// rollback best-effort; surface the original error
			}
			throw $e;
		}
	}

	/**
	 * Audit history for a record (most recent first), each entry decorated with
	 * the actor and a human-readable summary.
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws NotFoundException
	 */
	public function history(string $userId, int $recordId): array {
		$record = $this->findReadable($userId, $recordId); // read gate
		$out = [];
		foreach ($this->historyMapper->findByRecord($record->getId()) as $h) {
			$out[] = [
				'id' => $h->getId(),
				'action' => $h->getAction(),
				'user' => $h->getUserId(),
				'summary' => $h->getSummary(),
				'detail' => $h->getDetail() ? (json_decode($h->getDetail(), true) ?: null) : null,
				'created' => $h->getCreated(),
			];
		}
		return $out;
	}

	// ---- history helpers -------------------------------------------------

	/**
	 * Snapshot of a record's stored scalar values keyed by field id, used to
	 * diff an update. (Relation/file changes are summarised generically.)
	 *
	 * @return array<int,string>
	 */
	private function valueSnapshot(int $recordId): array {
		$rows = $this->valueMapper->findByRecordIds([$recordId])[$recordId] ?? [];
		$snap = [];
		foreach ($rows as $row) {
			$snap[(int)$row['field_id']] = implode('|', [
				$row['value_string'] ?? '',
				$row['value_number'] ?? '',
				$row['value_datetime'] ?? '',
				$row['value_bool'] ?? '',
			]);
		}
		return $snap;
	}

	/**
	 * @param Field[] $fields
	 * @param array<int,string> $before
	 * @param array<int,string> $after
	 * @return string[] the changed field labels
	 */
	private function logUpdate(int $registerId, int $recordId, string $userId, array $fields, array $before, array $after): array {
		$changedLabels = [];
		foreach ($fields as $field) {
			$fid = $field->getId();
			if (($before[$fid] ?? '') !== ($after[$fid] ?? '')) {
				$changedLabels[] = $field->getLabel();
			}
		}
		$n = count($changedLabels);
		$summary = $n === 0
			? 'Edited record'
			: ($n === 1 ? 'Changed ' . $changedLabels[0] : 'Changed ' . $n . ' fields');
		$this->logHistory($registerId, $recordId, $userId, 'update', $summary, $changedLabels);
		return $changedLabels;
	}

	/**
	 * @param string[] $changedFields
	 */
	private function logHistory(int $registerId, int $recordId, string $userId, string $action, string $summary, array $changedFields): void {
		try {
			$h = new History();
			$h->setRegisterId($registerId);
			$h->setRecordId($recordId);
			$h->setUserId($userId);
			$h->setAction($action);
			$h->setSummary($summary);
			$h->setDetail($changedFields === [] ? null : json_encode(['fields' => $changedFields], JSON_THROW_ON_ERROR));
			$h->setCreated($this->time->getTime());
			$this->historyMapper->insert($h);
		} catch (\Throwable $e) {
			// History is best-effort; never block the primary action on it.
		}
	}

	/**
	 * Apply each relation field's on-delete policy to references pointing at the
	 * record being deleted: block (refuse), cascade (soft-delete the referencing
	 * records) or null (drop the reference).
	 *
	 * @throws ValidationException when a 'block' policy forbids the deletion.
	 */
	private function enforceReferentialIntegrity(int $targetRecordId): void {
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

	// ---- helpers ---------------------------------------------------------

	/**
	 * A user may change a record if they created it, or if they manage the
	 * register. (Anyone with write access may create new records.)
	 *
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	private function requireOwnOrManage(string $userId, Record $record, Register $register): void {
		if ($record->getCreatedBy() === $userId) {
			return;
		}
		if (($this->registerService->permissionsFor($register, $userId) & Share::PERMISSION_MANAGE) !== 0) {
			return;
		}
		throw new ForbiddenException('You can only edit entries you created');
	}

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
	 * Run the rule engine: compute computed fields, enforce required and
	 * validation. Returns the value map with computed values applied.
	 *
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	private function validateAndCompute(int $registerId, array $fields, array $values, int $excludeRecordId): array {
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

	/**
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 */
	private function storeValues(int $recordId, array $fields, array $values): void {
		foreach ($fields as $field) {
			if (in_array($field->getType(), ['file', 'relation'], true)) {
				continue; // multi-valued, handled by storeFiles()/storeRefs()
			}
			$logical = $values[$field->getMachineName()] ?? null;
			$payload = FieldValue::toStorage($field->getType(), $logical);
			if ($payload['column'] !== '') {
				$this->valueMapper->insertValue($recordId, $field->getId(), $payload['column'], $payload['value']);
			}
		}
	}

	/**
	 * Persist a file field's referenced file ids into the join table. The value
	 * is an array of {id,...} objects or ids (one-or-more files).
	 *
	 * @param Field[] $fields
	 * @param array<string,mixed> $values
	 */
	private function storeFiles(int $recordId, array $fields, array $values): void {
		foreach ($fields as $field) {
			if ($field->getType() !== 'file') {
				continue;
			}
			$this->fileMapper->deleteForRecordField($recordId, $field->getId());
			$value = $values[$field->getMachineName()] ?? null;
			$list = is_array($value) && !isset($value['id']) ? $value : ($value === null || $value === '' ? [] : [$value]);
			$position = 0;
			foreach ($list as $item) {
				$fileId = is_array($item) ? (int)($item['id'] ?? 0) : (int)$item;
				if ($fileId > 0) {
					$this->fileMapper->insertFile($recordId, $field->getId(), $fileId, $position++);
				}
			}
		}
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
	private function storeRefs(int $recordId, array $fields, array $values): void {
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
	 * Value of an auto field, derived from the record's metadata.
	 *
	 * @return string|null
	 */
	private function autoValue(Field $field, Record $record): ?string {
		$cfg = json_decode($field->getConfig() ?? '{}', true) ?: [];
		return match ($cfg['kind'] ?? 'created_at') {
			'created_at' => $record->getCreated() ? gmdate('Y-m-d\TH:i', $record->getCreated()) : null,
			'updated_at' => $record->getUpdated() ? gmdate('Y-m-d\TH:i', $record->getUpdated()) : null,
			'created_by' => $record->getCreatedBy(),
			// Per-register sequence; fall back to the row id for any record
			// created before sequence numbers were introduced.
			'sequence' => (string)($record->getSeq() ?? $record->getId()),
			default => null,
		};
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
			if ($field->getType() === 'auto') {
				$values[$field->getMachineName()] = $this->autoValue($field, $record);
				continue;
			}
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
	private function resolveRelations(string $userId, array $fields, array $dtos): array {
		$relationFields = array_filter($fields, static fn (Field $f) => $f->getType() === 'relation');
		if (count($relationFields) === 0) {
			return $dtos;
		}
		$recordIds = array_map(static fn ($d) => $d['id'], $dtos);
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
		$recordIds = array_map(static fn ($d) => $d['id'], $dtos);
		$filesByRecord = $this->fileMapper->findByRecordIds($recordIds);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nameCache = [];
		$nameOf = function (int $fileId) use ($userFolder, &$nameCache): string {
			if (array_key_exists($fileId, $nameCache)) {
				return $nameCache[$fileId];
			}
			$name = 'file #' . $fileId;
			try {
				$nodes = $userFolder->getById($fileId);
				if (count($nodes) > 0) {
					$name = $nodes[0]->getName();
				}
			} catch (\Throwable) {
				// keep placeholder
			}
			return $nameCache[$fileId] = $name;
		};

		foreach ($dtos as &$dto) {
			$rows = $filesByRecord[$dto['id']] ?? [];
			foreach ($fileFields as $field) {
				$files = [];
				foreach ($rows as $row) {
					if ($row['field_id'] === $field->getId()) {
						$files[] = ['id' => $row['file_id'], 'name' => $nameOf($row['file_id'])];
					}
				}
				$dto['values'][$field->getMachineName()] = $files;
			}
		}
		unset($dto);
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
