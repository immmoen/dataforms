<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RecordMapper against the real migrated schema (per CI database engine). Covers
 * soft-delete exclusion, per-register sequence, the portable value-join search
 * and filter subqueries (the highest-risk EAV SQL), counts and purge. Each test
 * runs in a rolled-back transaction on unused register ids.
 */
class RecordMapperTest extends TestCase {
	private const REG = 991001;
	private const REG2 = 991002;

	private IDBConnection $db;
	private RecordMapper $mapper;
	private RecordValueMapper $values;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RecordMapper($this->db);
		$this->values = new RecordValueMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function insert(int $registerId = self::REG, int $seq = 1, string $owner = 'alice', ?int $deletedAt = null): Record {
		$r = new Record();
		$r->setRegisterId($registerId);
		$r->setOwner($owner);
		$r->setCreatedBy($owner);
		$r->setCreated(1_690_000_000);
		$r->setUpdated(1_690_000_000);
		$r->setSeq($seq);
		$r->setDeletedAt($deletedAt);
		return $this->mapper->insert($r);
	}

	public function testFindReturnsLiveRecordAndHidesSoftDeleted(): void {
		$live = $this->insert(self::REG, 1);
		$dead = $this->insert(self::REG, 2, 'alice', 12345);

		$found = $this->mapper->find($live->getId());
		$this->assertSame(self::REG, $found->getRegisterId());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find($dead->getId());
	}

	public function testMaxSeqCountsDeletedSoNumbersAreNeverReused(): void {
		$this->assertSame(0, $this->mapper->maxSeqForRegister(self::REG));
		$this->insert(self::REG, 1);
		$this->insert(self::REG, 2, 'alice', 999); // deleted but still counts
		$this->assertSame(2, $this->mapper->maxSeqForRegister(self::REG));
	}

	public function testExistingIdsInRegisterFiltersByRegisterAndLiveness(): void {
		$a = $this->insert(self::REG, 1);
		$b = $this->insert(self::REG, 2);
		$dead = $this->insert(self::REG, 3, 'alice', 1);
		$other = $this->insert(self::REG2, 1);

		$ids = [$a->getId(), $b->getId(), $dead->getId(), $other->getId(), 7777777];
		$live = $this->mapper->existingIdsInRegister($ids, self::REG);
		sort($live);
		$this->assertSame([$a->getId(), $b->getId()], $live);
		$this->assertSame([], $this->mapper->existingIdsInRegister([], self::REG));
	}

	public function testFindByRegisterIsLiveOnlyPaginatedAndSorted(): void {
		$r1 = $this->insert(self::REG, 1);
		$r2 = $this->insert(self::REG, 2);
		$r3 = $this->insert(self::REG, 3);
		$this->insert(self::REG, 4, 'alice', 99); // soft-deleted, excluded

		$bySeqAsc = $this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC');
		$this->assertSame([$r1->getId(), $r2->getId(), $r3->getId()], array_map(static fn (Record $r) => $r->getId(), $bySeqAsc));

		$firstPage = $this->mapper->findByRegister(self::REG, 2, 0, 'seq', 'ASC');
		$this->assertCount(2, $firstPage);
		$this->assertSame(3, $this->mapper->countByRegister(self::REG));
	}

	public function testSearchMatchesOnlyTheGivenStringFieldIds(): void {
		$field = 880001;
		$other = 880002;
		$match = $this->insert(self::REG, 1);
		$noMatch = $this->insert(self::REG, 2);
		$this->values->insertValue($match->getId(), $field, 'value_string', 'hello world');
		$this->values->insertValue($noMatch->getId(), $field, 'value_string', 'goodbye');

		// Scoped to the searchable field id, case-insensitive substring.
		$hits = $this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', 'WORLD', [], null, [$field]);
		$this->assertSame([$match->getId()], array_map(static fn (Record $r) => $r->getId(), $hits));
		$this->assertSame(1, $this->mapper->countByRegister(self::REG, 'WORLD', [], [$field]));

		// A different field id is out of scope → no match.
		$this->assertSame(0, $this->mapper->countByRegister(self::REG, 'world', [], [$other]));
		// No searchable fields at all → nothing can match.
		$this->assertSame(0, $this->mapper->countByRegister(self::REG, 'world', [], []));
	}

	public function testFilterSubqueriesAcrossOperators(): void {
		$field = 880010;
		$lo = $this->insert(self::REG, 1);
		$hi = $this->insert(self::REG, 2);
		$blank = $this->insert(self::REG, 3);
		$this->values->insertValue($lo->getId(), $field, 'value_number', 5);
		$this->values->insertValue($hi->getId(), $field, 'value_number', 50);
		// $blank has no value row for this field.

		$filter = static fn (string $op, $value = null) => [['fieldId' => $field, 'column' => 'value_number', 'op' => $op, 'value' => $value]];

		$this->assertSame([$hi->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('gte', 10))));
		$this->assertSame([$lo->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('lt', 10))));
		$this->assertSame([$lo->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('eq', 5))));
		// neq is "NOT IN (rows whose value = 5)", so a row with NO value for the
		// field also passes (it is not in the equality set). Frozen behaviour.
		$this->assertSame([$hi->getId(), $blank->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('neq', 5))));
		// isNotEmpty → rows that have a value; isEmpty → those that don't.
		$this->assertSame([$lo->getId(), $hi->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('isNotEmpty'))));
		$this->assertSame([$blank->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter('isEmpty'))));
	}

	public function testContainsFilterOnStringColumn(): void {
		$field = 880020;
		$a = $this->insert(self::REG, 1);
		$b = $this->insert(self::REG, 2);
		$this->values->insertValue($a->getId(), $field, 'value_string', 'Annual report');
		$this->values->insertValue($b->getId(), $field, 'value_string', 'Memo');
		$filter = [['fieldId' => $field, 'column' => 'value_string', 'op' => 'contains', 'value' => 'report']];
		$this->assertSame([$a->getId()], $this->ids($this->mapper->findByRegister(self::REG, 50, 0, 'seq', 'ASC', '', $filter)));
	}

	public function testSortByDataFieldViaCorrelatedValueJoin(): void {
		$field = 880030;
		$first = $this->insert(self::REG, 1);
		$second = $this->insert(self::REG, 2);
		$this->values->insertValue($first->getId(), $field, 'value_string', 'banana');
		$this->values->insertValue($second->getId(), $field, 'value_string', 'apple');

		$sortField = ['column' => 'value_string', 'fieldId' => $field];
		$asc = $this->mapper->findByRegister(self::REG, 50, 0, 'ignored', 'ASC', '', [], $sortField);
		// apple < banana → the second record sorts first.
		$this->assertSame([$second->getId(), $first->getId()], $this->ids($asc));
	}

	public function testFindOwnerByIdAndCountsAndPurge(): void {
		$a = $this->insert(self::REG, 1, 'bob');
		$this->insert(self::REG, 2, 'carol');
		$this->insert(self::REG, 3, 'carol', 5); // deleted — excluded from the count
		$this->insert(self::REG2, 1, 'dave');

		$this->assertSame('bob', $this->mapper->findOwnerById($a->getId()));
		$this->assertNull($this->mapper->findOwnerById(7777777));

		$counts = $this->mapper->countsByRegisterIds([self::REG, self::REG2]);
		$this->assertSame(2, $counts[self::REG]);
		$this->assertSame(1, $counts[self::REG2]);
		$this->assertSame([], $this->mapper->countsByRegisterIds([]));

		// Purge removes every row of the register, soft-deleted included.
		$this->mapper->deleteByRegister(self::REG);
		$this->assertSame(0, $this->mapper->countByRegister(self::REG));
		$this->assertSame([], $this->mapper->existingIdsInRegister([$a->getId()], self::REG));
	}

	/**
	 * @param Record[] $records
	 * @return int[]
	 */
	private function ids(array $records): array {
		return array_map(static fn (Record $r) => $r->getId(), $records);
	}
}
