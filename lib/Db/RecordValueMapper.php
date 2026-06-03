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

	public function deleteByRecord(int $recordId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByField(int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_record_values')
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
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
