<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Business logic for register fields (the schema). Access is gated through
 * RegisterService: reading a register's fields needs read access; changing
 * them needs manage rights.
 */
class FieldService {
	/**
	 * Supported field types for Phase 1. Relation, file attachment and
	 * computed fields arrive in later phases.
	 */
	public const TYPES = [
		'text', 'longtext', 'number', 'currency', 'percentage', 'boolean',
		'date', 'datetime', 'time', 'select', 'multiselect',
		'email', 'url', 'phone', 'user', 'group', 'relation', 'file',
		'computed', 'auto',
	];

	public const AUTO_KINDS = ['created_at', 'updated_at', 'created_by', 'sequence'];

	public function __construct(
		private FieldMapper $mapper,
		private RegisterService $registerService,
		private RecordValueMapper $valueMapper,
		private RecordFileMapper $fileMapper,
		private RecordRefMapper $refMapper,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return Field[]
	 * @throws NotFoundException when the register is not visible.
	 */
	public function listForRegister(string $userId, int $registerId): array {
		$this->registerService->find($userId, $registerId); // read gate
		return $this->mapper->findByRegister($registerId);
	}

	/**
	 * @param array{label?:string,type?:string,machineName?:string,config?:mixed,mandatory?:bool,unique?:bool,default?:string} $data
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, array $data): Field {
		$this->registerService->findManageable($userId, $registerId); // manage gate

		$type = (string)($data['type'] ?? '');
		if (!in_array($type, self::TYPES, true)) {
			throw new ValidationException('Unknown field type: ' . $type);
		}
		$label = trim((string)($data['label'] ?? ''));
		if ($label === '') {
			throw new ValidationException('Label is required');
		}

		$machineName = trim((string)($data['machineName'] ?? ''));
		$machineName = $machineName !== '' ? $this->normaliseMachineName($machineName) : $this->slugify($label);
		$machineName = $this->ensureUnique($registerId, $machineName);

		$field = new Field();
		$field->setRegisterId($registerId);
		$field->setMachineName($machineName);
		$field->setLabel($label);
		$field->setType($type);
		$field->setConfig($this->encodeConfig($type, $data['config'] ?? []));
		$field->setPosition($this->mapper->maxPosition($registerId) + 1);
		$field->setMandatory((bool)($data['mandatory'] ?? false));
		$field->setIsUnique((bool)($data['unique'] ?? false));
		$field->setDefaultValue(isset($data['default']) ? (string)$data['default'] : null);

		return $this->mapper->insert($field);
	}

	/**
	 * The machine name is immutable; any 'machineName' in $changes is ignored.
	 *
	 * @param array<string,mixed> $changes
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function update(string $userId, int $fieldId, array $changes): Field {
		$field = $this->findOwned($userId, $fieldId, manage: true);

		if (array_key_exists('label', $changes)) {
			$label = trim((string)$changes['label']);
			if ($label === '') {
				throw new ValidationException('Label cannot be empty');
			}
			$field->setLabel($label);
		}
		if (array_key_exists('config', $changes)) {
			$field->setConfig($this->encodeConfig($field->getType(), $changes['config']));
		}
		if (array_key_exists('mandatory', $changes)) {
			$field->setMandatory((bool)$changes['mandatory']);
		}
		if (array_key_exists('unique', $changes)) {
			$field->setIsUnique((bool)$changes['unique']);
		}
		if (array_key_exists('default', $changes)) {
			$field->setDefaultValue($changes['default'] === null ? null : (string)$changes['default']);
		}

		return $this->mapper->update($field);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $fieldId): void {
		$field = $this->findOwned($userId, $fieldId, manage: true);
		$this->valueMapper->deleteByField($fieldId); // clean up stored values
		$this->fileMapper->deleteForField($fieldId); // and any attached-file refs
		$this->refMapper->deleteForField($fieldId); // and any relation refs
		// Soft-delete: keep the row as a name tombstone so its machine_name stays
		// reserved and a reused name can't silently re-bind a stale rule to a new
		// field (audit M2). Active queries filter deleted_at; purge hard-deletes it.
		$field->setDeletedAt($this->time->getTime());
		$this->mapper->update($field);
	}

	/**
	 * Persist a new field order for a register.
	 *
	 * @param int[] $orderedIds field ids in the desired order
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function reorder(string $userId, int $registerId, array $orderedIds): array {
		$this->registerService->findManageable($userId, $registerId);
		$fields = $this->mapper->findByRegister($registerId);
		$byId = [];
		foreach ($fields as $f) {
			$byId[$f->getId()] = $f;
		}
		$position = 0;
		foreach ($orderedIds as $id) {
			$id = (int)$id;
			if (isset($byId[$id])) {
				$byId[$id]->setPosition($position++);
				$this->mapper->update($byId[$id]);
			}
		}
		return $this->mapper->findByRegister($registerId);
	}

	// ---- helpers ---------------------------------------------------------

	private function findOwned(string $userId, int $fieldId, bool $manage): Field {
		try {
			$field = $this->mapper->find($fieldId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Field not found');
		}
		// Gate via the owning register (read or manage as required).
		if ($manage) {
			$this->registerService->findManageable($userId, $field->getRegisterId());
		} else {
			$this->registerService->find($userId, $field->getRegisterId());
		}
		return $field;
	}

	private function slugify(string $label): string {
		$slug = strtolower($label);
		$slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
		$slug = trim($slug, '_');
		if ($slug === '' || !preg_match('/^[a-z]/', $slug)) {
			$slug = 'field_' . $slug;
		}
		return substr($slug, 0, 60);
	}

	private function normaliseMachineName(string $name): string {
		if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
			throw new ValidationException('Machine name must be lower-case letters, digits and underscores, starting with a letter');
		}
		return substr($name, 0, 64);
	}

	private function ensureUnique(int $registerId, string $base): string {
		$candidate = $base;
		$i = 2;
		while ($this->mapper->machineNameExists($registerId, $candidate)) {
			$candidate = substr($base, 0, 60) . '_' . $i;
			$i++;
		}
		return $candidate;
	}

	/**
	 * Validate and normalise type-specific config, returning a JSON string.
	 *
	 * @param mixed $config
	 */
	private function encodeConfig(string $type, $config): string {
		$config = is_array($config) ? $config : [];
		$clean = [];

		if (in_array($type, ['select', 'multiselect'], true)) {
			$options = [];
			foreach (($config['options'] ?? []) as $opt) {
				$opt = trim((string)$opt);
				if ($opt !== '') {
					$options[] = $opt;
				}
			}
			$clean['options'] = array_values(array_unique($options));
			$clean['allowOther'] = (bool)($config['allowOther'] ?? false);
			// Optional grouping for long option lists: a JS-compatible regex
			// source the data-entry picker uses to bucket options under
			// collapsible parents. Stored opaquely (display concern only); the
			// selected values remain a flat list.
			$groupPattern = trim((string)($config['groupPattern'] ?? ''));
			if ($groupPattern !== '') {
				$clean['groupPattern'] = mb_substr($groupPattern, 0, 200);
			}
		}

		if (in_array($type, ['number', 'currency', 'percentage'], true)) {
			if (isset($config['min']) && $config['min'] !== '') {
				$clean['min'] = (float)$config['min'];
			}
			if (isset($config['max']) && $config['max'] !== '') {
				$clean['max'] = (float)$config['max'];
			}
			$clean['decimals'] = max(0, min(6, (int)($config['decimals'] ?? ($type === 'currency' ? 2 : 0))));
		}

		if (in_array($type, ['text', 'longtext'], true) && isset($config['maxLength']) && $config['maxLength'] !== '') {
			$clean['maxLength'] = max(1, (int)$config['maxLength']);
		}

		if ($type === 'relation') {
			$target = (int)($config['targetRegisterId'] ?? 0);
			if ($target <= 0) {
				throw new ValidationException('A relation field needs a target register');
			}
			$clean['targetRegisterId'] = $target;
			$clean['displayField'] = trim((string)($config['displayField'] ?? ''));
			$clean['multiple'] = (bool)($config['multiple'] ?? false);
			$onDelete = (string)($config['onDelete'] ?? 'null');
			$clean['onDelete'] = in_array($onDelete, ['null', 'block', 'cascade'], true) ? $onDelete : 'null';
		}

		if ($type === 'computed') {
			$expr = trim((string)($config['expression'] ?? ''));
			if ($expr === '') {
				throw new ValidationException('A computed field needs an expression');
			}
			$clean['expression'] = $expr;
		}

		if ($type === 'auto') {
			$kind = (string)($config['kind'] ?? 'created_at');
			$clean['kind'] = in_array($kind, self::AUTO_KINDS, true) ? $kind : 'created_at';
		}

		// Optional help text, available on every field type.
		$help = trim((string)($config['help'] ?? ''));
		if ($help !== '') {
			$clean['help'] = mb_substr($help, 0, 500);
		}

		return json_encode($clean, JSON_THROW_ON_ERROR);
	}
}
