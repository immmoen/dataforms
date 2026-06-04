<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Form>
 */
class FormMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'df_forms', Form::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Form {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Form[]
	 */
	public function findByRegister(int $registerId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByRegister(int $registerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
