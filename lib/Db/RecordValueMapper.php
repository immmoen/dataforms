<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read/write helper for the EAV value store (df_record_values). Not a QBMapper
 * because the populated column varies per field type.
 */
class RecordValueMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	private const COLUMNS = [
		'value_string', 'value_number', 'value_datetime', 'value_bool',
		'value_file_id', 'value_ref_record_id',
	];

	/**
	 * Insert one value row, writing only the given typed column.
	 *
	 * @param mixed $value
	 */
	public function insertValue(int $recordId, int $fieldId, string $column, $value): void {
		if (!in_array($column, self::COLUMNS, true)) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert('df_record_values')
			->setValue('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT))
			->setValue('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT))
			->setValue($column, $qb->createNamedParameter($value));
		$qb->executeStatement();
	}

	/** Remove the stored value for one (record, field) — used by the set-field action. */
	public function deleteForRecordField(int $recordId, int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByRecord(int $recordId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Whether another (non-deleted) record already has this value for a field.
	 * Used to enforce a field's unique constraint.
	 *
	 * @param mixed $value
	 */
	public function valueExistsForField(int $fieldId, string $column, $value, int $excludeRecordId): bool {
		if (!in_array($column, self::COLUMNS, true)) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('rv.record_id')
			->from('df_record_values', 'rv')
			->innerJoin('rv', 'df_records', 'r', $qb->expr()->eq('r.id', 'rv.record_id'))
			->where($qb->expr()->eq('rv.field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rv.' . $column, $qb->createNamedParameter($value)))
			->andWhere($qb->expr()->isNull('r.deleted_at'))
			->andWhere($qb->expr()->neq('rv.record_id', $qb->createNamedParameter($excludeRecordId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$exists = $result->fetchOne() !== false;
		$result->closeCursor();
		return $exists;
	}

	public function deleteByField(int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete every value row belonging to a set of field ids (used by register
	 * purge — a register's values all hang off its field ids).
	 *
	 * @param int[] $fieldIds
	 */
	public function deleteByFieldIds(array $fieldIds): void {
		if ($fieldIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->in('field_id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * All value rows for a set of records, keyed by record id.
	 *
	 * @param int[] $recordIds
	 * @return array<int,array<int,array<string,mixed>>> recordId => list of rows
	 */
	public function findByRecordIds(array $recordIds): array {
		if (count($recordIds) === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('df_record_values')
			->where($qb->expr()->in('record_id', $qb->createNamedParameter($recordIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetch()) {
			$grouped[(int)$row['record_id']][] = $row;
		}
		$result->closeCursor();
		return $grouped;
	}
}
