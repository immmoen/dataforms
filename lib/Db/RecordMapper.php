<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Record>
 */
class RecordMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_records', Record::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Record {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		return $this->findEntity($qb);
	}

	/**
	 * Paginated, optionally searched list of records in a register.
	 *
	 * @return Record[]
	 */
	public function findByRegister(int $registerId, int $limit = 50, int $offset = 0, string $sort = 'updated', string $direction = 'DESC', string $search = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));

		$this->applySearch($qb, $search);

		$sortColumn = in_array($sort, ['created', 'updated', 'id'], true) ? $sort : 'updated';
		$dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
		$qb->orderBy('r.' . $sortColumn, $dir)
			->addOrderBy('r.id', 'DESC')
			->setMaxResults(max(1, min(500, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	public function countByRegister(int $registerId, string $search = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('r.id'))
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));
		$this->applySearch($qb, $search);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function applySearch(IQueryBuilder $qb, string $search): void {
		$search = trim($search);
		if ($search === '') {
			return;
		}
		$sub = $this->db->getQueryBuilder();
		$sub->select('rv.record_id')
			->from('df_record_values', 'rv')
			->where($sub->expr()->eq('rv.record_id', 'r.id'))
			->andWhere($sub->expr()->iLike('rv.value_string', $qb->createNamedParameter(
				'%' . $this->db->escapeLikeParameter($search) . '%'
			)));
		$qb->andWhere($qb->expr()->exists($sub->getSQL()));
	}
}
