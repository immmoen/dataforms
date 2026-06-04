<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read/write helper for multi-valued relation fields (df_rec_refs). Each row
 * references one target record for a (record, field).
 */
class RecordRefMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function insertRef(int $recordId, int $fieldId, int $targetRecordId, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('df_rec_refs')
			->setValue('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT))
			->setValue('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT))
			->setValue('target_record_id', $qb->createNamedParameter($targetRecordId, IQueryBuilder::PARAM_INT))
			->setValue('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	public function deleteForRecordField(int $recordId, int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_refs')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForRecord(int $recordId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_refs')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForField(int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_refs')
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @param int[] $recordIds
	 * @return array<int,array<int,array{field_id:int,target_record_id:int,position:int}>>
	 */
	public function findByRecordIds(array $recordIds): array {
		if (count($recordIds) === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('record_id', 'field_id', 'target_record_id', 'position')
			->from('df_rec_refs')
			->where($qb->expr()->in('record_id', $qb->createNamedParameter($recordIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetch()) {
			$grouped[(int)$row['record_id']][] = [
				'field_id' => (int)$row['field_id'],
				'target_record_id' => (int)$row['target_record_id'],
				'position' => (int)$row['position'],
			];
		}
		$result->closeCursor();
		return $grouped;
	}

	/**
	 * Rows that reference a given target record (for referential integrity).
	 *
	 * @return array<int,array{id:int,record_id:int,field_id:int}>
	 */
	public function findReferencingTarget(int $targetRecordId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'record_id', 'field_id')
			->from('df_rec_refs')
			->where($qb->expr()->eq('target_record_id', $qb->createNamedParameter($targetRecordId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'record_id' => (int)$row['record_id'],
				'field_id' => (int)$row['field_id'],
			];
		}
		$result->closeCursor();
		return $rows;
	}

	public function deleteRefsToTarget(int $targetRecordId, int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_refs')
			->where($qb->expr()->eq('target_record_id', $qb->createNamedParameter($targetRecordId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
