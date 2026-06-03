<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_shares', Share::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Share[]
	 */
	public function findByRegister(int $registerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The OR of all permission bits granted to a user (directly or via groups)
	 * for a register. 0 if not shared.
	 *
	 * @param string[] $groupIds
	 */
	public function permissionsFor(int $registerId, string $userId, array $groupIds): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('permissions', 'share_type', 'share_with')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$perms = 0;
		while ($row = $result->fetch()) {
			$type = (int)$row['share_type'];
			$with = (string)$row['share_with'];
			if (($type === Share::TYPE_USER && $with === $userId)
				|| ($type === Share::TYPE_GROUP && in_array($with, $groupIds, true))) {
				$perms |= (int)$row['permissions'];
			}
		}
		$result->closeCursor();
		return $perms;
	}

	public function findExisting(int $registerId, int $shareType, string $shareWith): ?Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($shareType, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($shareWith)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteByRegister(int $registerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
