<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<History>
 */
class HistoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_history', History::class);
	}

	/**
	 * Most-recent-first history for a record.
	 *
	 * @return History[]
	 */
	public function findByRecord(int $recordId, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('record_id', $qb->createNamedParameter($recordId, IQueryBuilder::PARAM_INT)))
			->orderBy('created', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Delete all history for a register (used by register purge). */
	public function deleteByRegister(int $registerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
