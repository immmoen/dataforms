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
	 * Of the given record ids, return those that are live (not soft-deleted) and
	 * belong to the given register. Used to validate relation target ids on write.
	 *
	 * @param int[] $ids
	 * @return int[] the subset of $ids that exist in $registerId
	 */
	public function existingIdsInRegister(array $ids, int $registerId): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'));
		$result = $qb->executeQuery();
		$out = [];
		foreach ($result->fetchAll() as $row) {
			$out[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * Paginated, optionally searched list of records in a register.
	 *
	 * @return Record[]
	 */
	/**
	 * @param array<int,array{fieldId:int,column:string,op:string,value:mixed}> $filters
	 * @param array{column:string,fieldId:int}|null $sortField sort by a data field
	 * @return Record[]
	 */
	public function findByRegister(int $registerId, int $limit = 50, int $offset = 0, string $sort = 'updated', string $direction = 'DESC', string $search = '', array $filters = [], ?array $sortField = null, array $searchFieldIds = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));

		$this->applySearch($qb, $search, $searchFieldIds);
		$this->applyFilters($qb, $filters);

		$dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
		if ($sortField !== null && in_array($sortField['column'], self::FILTERABLE_COLUMNS, true)) {
			// Sort by a data field via a correlated value lookup.
			$qb->leftJoin('r', 'df_record_values', 'sv', $qb->expr()->andX(
				$qb->expr()->eq('sv.record_id', 'r.id'),
				$qb->expr()->eq('sv.field_id', $qb->createNamedParameter($sortField['fieldId'], IQueryBuilder::PARAM_INT)),
			));
			$qb->orderBy('sv.' . $sortField['column'], $dir)->addOrderBy('r.id', 'DESC');
		} else {
			$sortColumn = in_array($sort, ['created', 'updated', 'id', 'seq', 'created_by'], true) ? $sort : 'updated';
			$qb->orderBy('r.' . $sortColumn, $dir)->addOrderBy('r.id', 'DESC');
		}

		$qb->setMaxResults(max(1, min(500, $limit)))->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/**
	 * @param array<int,array{fieldId:int,column:string,op:string,value:mixed}> $filters
	 */
	public function countByRegister(int $registerId, string $search = '', array $filters = [], array $searchFieldIds = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('r.id'))
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));
		$this->applySearch($qb, $search, $searchFieldIds);
		$this->applyFilters($qb, $filters);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * Highest per-register sequence number assigned so far (0 if none). Counts
	 * deleted records too, so numbers are never reused after a deletion.
	 */
	/** Hard-delete every record row of a register, soft-deleted ones included (purge). */
	public function deleteByRegister(int $registerId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function maxSeqForRegister(int $registerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('seq'))
			->from($this->getTableName())
			->where($qb->expr()->eq('register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return $max === null || $max === false ? 0 : (int)$max;
	}

	/**
	 * Non-deleted record counts for a set of registers, keyed by register id.
	 *
	 * @param int[] $registerIds
	 * @return array<int,int>
	 */
	public function countsByRegisterIds(array $registerIds): array {
		if (count($registerIds) === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('register_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->in('register_id', $qb->createNamedParameter($registerIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('deleted_at'))
			->groupBy('register_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['register_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();
		return $counts;
	}

	private const FILTERABLE_COLUMNS = ['value_string', 'value_number', 'value_datetime', 'value_bool'];

	/**
	 * Apply field-based filter criteria as portable IN / NOT IN subqueries.
	 *
	 * @param array<int,array{fieldId:int,column:string,op:string,value:mixed}> $filters
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void {
		foreach ($filters as $f) {
			$column = (string)($f['column'] ?? '');
			$op = (string)($f['op'] ?? 'eq');
			$fieldId = (int)($f['fieldId'] ?? 0);
			if (!in_array($column, self::FILTERABLE_COLUMNS, true) || $fieldId <= 0) {
				continue;
			}
			$sub = $this->db->getQueryBuilder();
			$sub->select('fv.record_id')
				->from('df_record_values', 'fv')
				->where($sub->expr()->eq('fv.field_id', $qb->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)));
			$negate = false;
			$col = 'fv.' . $column;
			switch ($op) {
				case 'eq':
					$sub->andWhere($col . ' = ' . $qb->createNamedParameter($f['value']));
					break;
				case 'neq':
					$sub->andWhere($col . ' = ' . $qb->createNamedParameter($f['value']));
					$negate = true;
					break;
				case 'contains':
					$sub->andWhere($sub->expr()->iLike($col, $qb->createNamedParameter('%' . $this->db->escapeLikeParameter((string)$f['value']) . '%')));
					break;
				case 'gt': $sub->andWhere($col . ' > ' . $qb->createNamedParameter($f['value'])); break;
				case 'lt': $sub->andWhere($col . ' < ' . $qb->createNamedParameter($f['value'])); break;
				case 'gte': $sub->andWhere($col . ' >= ' . $qb->createNamedParameter($f['value'])); break;
				case 'lte': $sub->andWhere($col . ' <= ' . $qb->createNamedParameter($f['value'])); break;
				case 'isNotEmpty': $sub->andWhere($sub->expr()->isNotNull($col)); break;
				case 'isEmpty': $sub->andWhere($sub->expr()->isNotNull($col)); $negate = true; break;
				default: continue 2;
			}
			$qb->andWhere('r.id ' . ($negate ? 'NOT ' : '') . 'IN (' . $sub->getSQL() . ')');
		}
	}

	/**
	 * Restrict the result set to records with a string value matching $search.
	 *
	 * The value subquery is scoped to the register's searchable string-field ids
	 * ($searchFieldIds) so it touches only this register's text values instead of
	 * scanning df_record_values instance-wide (audit M6). With no searchable
	 * fields a text search can never match, so the result is forced empty.
	 *
	 * @param int[] $searchFieldIds
	 */
	private function applySearch(IQueryBuilder $qb, string $search, array $searchFieldIds = []): void {
		$search = trim($search);
		if ($search === '') {
			return;
		}
		if ($searchFieldIds === []) {
			$qb->andWhere('1 = 0'); // no string fields → nothing can match
			return;
		}
		// Portable across engines: restrict to records that have a matching
		// value via an IN subquery (Nextcloud's expression builder has no
		// exists()). Both parameters are bound on the outer builder so their
		// placeholders resolve when the combined query runs.
		$param = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%');
		$sub = $this->db->getQueryBuilder();
		$sub->select('rv.record_id')
			->from('df_record_values', 'rv')
			->where($sub->expr()->iLike('rv.value_string', $param))
			->andWhere($sub->expr()->in('rv.field_id', $qb->createNamedParameter($searchFieldIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->andWhere('r.id IN (' . $sub->getSQL() . ')');
	}
}
