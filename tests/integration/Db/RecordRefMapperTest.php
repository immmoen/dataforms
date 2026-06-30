<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RecordRefMapper against the real migrated schema. Covers position-ordered
 * relation rows, the per-record/field and per-record clears, referential-
 * integrity lookups (rows referencing a target), and the purge helpers
 * including the portable incoming-reference subquery on df_records.
 */
class RecordRefMapperTest extends TestCase {
	private const REG = 993001;

	private IDBConnection $db;
	private RecordRefMapper $mapper;
	private RecordMapper $records;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RecordRefMapper($this->db);
		$this->records = new RecordMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function record(int $registerId = self::REG, int $seq = 1): Record {
		$r = new Record();
		$r->setRegisterId($registerId);
		$r->setOwner('alice');
		$r->setCreatedBy('alice');
		$r->setCreated(1_690_000_000);
		$r->setUpdated(1_690_000_000);
		$r->setSeq($seq);
		return $this->records->insert($r);
	}

	public function testInsertAndFindByRecordIdsIsPositionOrdered(): void {
		$rec = $this->record();
		$this->mapper->insertRef($rec->getId(), 10, 501, 1);
		$this->mapper->insertRef($rec->getId(), 10, 500, 0);

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([500, 501], array_map(static fn ($r) => $r['target_record_id'], $rows));
		$this->assertSame([], $this->mapper->findByRecordIds([]));
	}

	public function testDeleteForRecordFieldAndForRecord(): void {
		$rec = $this->record();
		$this->mapper->insertRef($rec->getId(), 10, 500, 0);
		$this->mapper->insertRef($rec->getId(), 11, 600, 0);

		$this->mapper->deleteForRecordField($rec->getId(), 10);
		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([11], array_map(static fn ($r) => (int)$r['field_id'], $rows));

		$this->mapper->deleteForRecord($rec->getId());
		$this->assertSame([], $this->mapper->findByRecordIds([$rec->getId()]));
	}

	public function testFindReferencingTargetAndDeleteRefsToTarget(): void {
		$a = $this->record(self::REG, 1);
		$b = $this->record(self::REG, 2);
		$this->mapper->insertRef($a->getId(), 10, 9000, 0);
		$this->mapper->insertRef($b->getId(), 10, 9000, 0);

		$referencing = $this->mapper->findReferencingTarget(9000);
		$this->assertCount(2, $referencing);
		$this->assertEqualsCanonicalizing([$a->getId(), $b->getId()], array_map(static fn ($r) => $r['record_id'], $referencing));

		$this->mapper->deleteRefsToTarget(9000, 10);
		$this->assertSame([], $this->mapper->findReferencingTarget(9000));
	}

	public function testDeleteForFieldAndByFieldIds(): void {
		$rec = $this->record();
		$this->mapper->insertRef($rec->getId(), 10, 500, 0);
		$this->mapper->insertRef($rec->getId(), 11, 600, 0);
		$this->mapper->insertRef($rec->getId(), 12, 700, 0);

		$this->mapper->deleteForField(10);
		$this->mapper->deleteByFieldIds([11]);
		$this->mapper->deleteByFieldIds([]); // no-op

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([12], array_map(static fn ($r) => (int)$r['field_id'], $rows));
	}

	public function testDeleteByTargetRegisterDropsIncomingReferences(): void {
		// A record in another register points at a record in self::REG; purging
		// self::REG must drop that dangling incoming reference.
		$target = $this->record(self::REG, 1);
		$sourceReg = 993099;
		$source = $this->record($sourceReg, 1);
		$this->mapper->insertRef($source->getId(), 10, $target->getId(), 0);

		$this->mapper->deleteByTargetRegister(self::REG);
		$this->assertSame([], $this->mapper->findReferencingTarget($target->getId()));
	}
}
