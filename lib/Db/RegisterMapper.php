<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Register>
 */
class RegisterMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_registers', Register::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Register {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		return $this->findEntity($qb);
	}

	/**
	 * Registers owned by a user, or shared to the user or one of their groups.
	 *
	 * @param string[] $groupIds
	 * @return Register[]
	 */
	public function findAllForUser(string $userId, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();

		$shareConditions = $qb->expr()->orX(
			$qb->expr()->eq('r.owner', $qb->createNamedParameter($userId)),
		);

		// share_type 0 = user, 1 = group (see migration).
		$userShare = $qb->expr()->andX(
			$qb->expr()->eq('s.share_type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
			$qb->expr()->eq('s.share_with', $qb->createNamedParameter($userId)),
		);
		$shareConditions->add($userShare);

		if (count($groupIds) > 0) {
			$groupShare = $qb->expr()->andX(
				$qb->expr()->eq('s.share_type', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('s.share_with', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
			$shareConditions->add($groupShare);
		}

		$qb->selectDistinct('r.*')
			->from($this->getTableName(), 'r')
			->leftJoin('r', 'df_shares', 's', $qb->expr()->eq('s.register_id', 'r.id'))
			->where($qb->expr()->isNull('r.deleted_at'))
			->andWhere($shareConditions)
			->orderBy('r.title', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Ids of registers soft-deleted at or before $cutoff (epoch seconds) — the
	 * retention job's purge candidates.
	 *
	 * @return int[]
	 */
	public function findSoftDeletedBefore(int $cutoff): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->isNotNull('deleted_at'))
			->andWhere($qb->expr()->lte('deleted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = [];
		foreach ($result->fetchAll() as $row) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/** Hard-delete a register row by id (used by purge, after its children are gone). */
	public function deleteById(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
