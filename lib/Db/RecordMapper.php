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
	/**
	 * @param array<int,array{fieldId:int,column:string,op:string,value:mixed}> $filters
	 * @param array{column:string,fieldId:int}|null $sortField sort by a data field
	 * @return Record[]
	 */
	public function findByRegister(int $registerId, int $limit = 50, int $offset = 0, string $sort = 'updated', string $direction = 'DESC', string $search = '', array $filters = [], ?array $sortField = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));

		$this->applySearch($qb, $search);
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
			$sortColumn = in_array($sort, ['created', 'updated', 'id'], true) ? $sort : 'updated';
			$qb->orderBy('r.' . $sortColumn, $dir)->addOrderBy('r.id', 'DESC');
		}

		$qb->setMaxResults(max(1, min(500, $limit)))->setFirstResult(max(0, $offset));
		return $this->findEntities($qb);
	}

	/**
	 * @param array<int,array{fieldId:int,column:string,op:string,value:mixed}> $filters
	 */
	public function countByRegister(int $registerId, string $search = '', array $filters = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('r.id'))
			->from($this->getTableName(), 'r')
			->where($qb->expr()->eq('r.register_id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('r.deleted_at'));
		$this->applySearch($qb, $search);
		$this->applyFilters($qb, $filters);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
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

	private function applySearch(IQueryBuilder $qb, string $search): void {
		$search = trim($search);
		if ($search === '') {
			return;
		}
		// Portable across engines: restrict to records that have a matching
		// value via an IN subquery (Nextcloud's expression builder has no
		// exists()). The LIKE parameter is bound on the outer builder so its
		// placeholder resolves when the combined query runs.
		$param = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%');
		$sub = $this->db->getQueryBuilder();
		$sub->select('rv.record_id')
			->from('df_record_values', 'rv')
			->where($sub->expr()->iLike('rv.value_string', $param));
		$qb->andWhere('r.id IN (' . $sub->getSQL() . ')');
	}
}
