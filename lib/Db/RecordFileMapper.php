<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Read/write helper for multi-valued file-attachment fields (df_rec_files).
 * Each row references one Nextcloud file by id for a (record, field).
 */
class RecordFileMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function insertFile(int $recordId, int $fieldId, int $fileId, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('df_rec_files')
			->setValue('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT))
			->setValue('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT))
			->setValue('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->setValue('position', $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT));
		$qb->executeStatement();
	}

	public function deleteForRecordField(int $recordId, int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_files')
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteForField(int $fieldId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_files')
			->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete every file-attachment row belonging to a set of field ids (register purge).
	 *
	 * @param int[] $fieldIds
	 */
	public function deleteByFieldIds(array $fieldIds): void {
		if ($fieldIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('df_rec_files')
			->where($qb->expr()->in('field_id', $qb->createNamedParameter($fieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}

	/**
	 * @param int[] $recordIds
	 * @return array<int,array<int,array{field_id:int,file_id:int,position:int}>> recordId => rows
	 */
	public function findByRecordIds(array $recordIds): array {
		if (count($recordIds) === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('record_id', 'field_id', 'file_id', 'position')
			->from('df_rec_files')
			->where($qb->expr()->in('record_id', $qb->createNamedParameter($recordIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$grouped = [];
		while ($row = $result->fetch()) {
			$grouped[(int)$row['record_id']][] = [
				'field_id' => (int)$row['field_id'],
				'file_id' => (int)$row['file_id'],
				'position' => (int)$row['position'],
			];
		}
		$result->closeCursor();
		return $grouped;
	}
}
