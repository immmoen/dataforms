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

	/**
	 * Forms across a set of registers (the ones a user can read), optionally
	 * filtered by a term matching the form or register title — one JOINed query
	 * for the unified-search / Smart Picker, instead of a per-register loop
	 * (audit M7). The register title is returned alongside each form.
	 *
	 * @param int[] $registerIds
	 * @return array<int,array{id:int,title:string,register_id:int,register_title:string}>
	 */
	public function searchForUser(array $registerIds, string $term, int $limit): array {
		if ($registerIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('f.id', 'f.title', 'f.register_id')
			->selectAlias('r.title', 'register_title')
			->from($this->getTableName(), 'f')
			->innerJoin('f', 'df_registers', 'r', $qb->expr()->eq('r.id', 'f.register_id'))
			->where($qb->expr()->in('f.register_id', $qb->createNamedParameter($registerIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));
		if ($term !== '') {
			$like = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('r.title', $like),
				$qb->expr()->iLike('f.title', $like),
			));
		}
		$qb->orderBy('r.title', 'ASC')
			->addOrderBy('f.position', 'ASC')
			->setMaxResults(max(1, min(100, $limit)));
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = [
				'id' => (int)$row['id'],
				'title' => (string)$row['title'],
				'register_id' => (int)$row['register_id'],
				'register_title' => (string)$row['register_title'],
			];
		}
		$result->closeCursor();
		return $out;
	}
}
