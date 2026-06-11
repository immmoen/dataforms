<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AutomationLog>
 */
class AutomationLogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_automation_log', AutomationLog::class);
	}

	/**
	 * Most-recent-first activity for a register.
	 *
	 * @return AutomationLog[]
	 */
	public function findByRegister(int $registerId, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->orderBy('created', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(max(1, min($limit, 500)));
		return $this->findEntities($qb);
	}

	/** Delete all log entries for a register (used by register purge). */
	public function deleteByRegister(int $registerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete entries older than the cutoff timestamp (retention sweep).
	 *
	 * @return int rows deleted
	 */
	public function deleteOlderThan(int $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
