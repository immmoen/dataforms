<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RecordValueMapper against the real migrated schema. Covers the typed-column
 * EAV writes, per-(record,field) and per-record/field deletes, the uniqueness
 * probe (which joins df_records to ignore soft-deleted rows), and the grouped
 * read. The mapper is deliberately not a QBMapper since the populated column
 * varies — this is the portability-critical store.
 */
class RecordValueMapperTest extends TestCase {
	private const REG = 992001;

	private IDBConnection $db;
	private RecordValueMapper $mapper;
	private RecordMapper $records;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RecordValueMapper($this->db);
		$this->records = new RecordMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function record(int $seq = 1, ?int $deletedAt = null): Record {
		$r = new Record();
		$r->setRegisterId(self::REG);
		$r->setOwner('alice');
		$r->setCreatedBy('alice');
		$r->setCreated(1_690_000_000);
		$r->setUpdated(1_690_000_000);
		$r->setSeq($seq);
		$r->setDeletedAt($deletedAt);
		return $this->records->insert($r);
	}

	public function testInsertWritesOnlyTheGivenTypedColumn(): void {
		$rec = $this->record();
		$this->mapper->insertValue($rec->getId(), 101, 'value_string', 'hello');
		$this->mapper->insertValue($rec->getId(), 102, 'value_number', 42);
		$this->mapper->insertValue($rec->getId(), 103, 'value_bool', 1);

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$byField = [];
		foreach ($rows as $row) {
			$byField[(int)$row['field_id']] = $row;
		}
		$this->assertSame('hello', $byField[101]['value_string']);
		$this->assertNull($byField[101]['value_number'], 'only the chosen column is populated');
		$this->assertSame(42, (int)$byField[102]['value_number']);
		$this->assertSame(1, (int)$byField[103]['value_bool']);
	}

	public function testInsertIgnoresUnknownColumn(): void {
		$rec = $this->record();
		$this->mapper->insertValue($rec->getId(), 101, 'value_bogus', 'x'); // silently ignored
		$this->assertSame([], $this->mapper->findByRecordIds([$rec->getId()]));
	}

	public function testDeleteForRecordFieldAndDeleteByRecord(): void {
		$rec = $this->record();
		$this->mapper->insertValue($rec->getId(), 101, 'value_string', 'a');
		$this->mapper->insertValue($rec->getId(), 102, 'value_string', 'b');

		$this->mapper->deleteForRecordField($rec->getId(), 101);
		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([102], array_map(static fn ($r) => (int)$r['field_id'], $rows));

		$this->mapper->deleteByRecord($rec->getId());
		$this->assertSame([], $this->mapper->findByRecordIds([$rec->getId()]));
	}

	public function testValueExistsForFieldIgnoresSelfDeletedAndUnknownColumn(): void {
		$a = $this->record(1);
		$b = $this->record(2);
		$deleted = $this->record(3, 999);
		$this->mapper->insertValue($a->getId(), 200, 'value_string', 'SN-1');
		$this->mapper->insertValue($deleted->getId(), 200, 'value_string', 'SN-2');

		// Another live record holds 'SN-1' → taken (excluding the record itself).
		$this->assertTrue($this->mapper->valueExistsForField(200, 'value_string', 'SN-1', $b->getId()));
		// Excluding the only holder → not taken.
		$this->assertFalse($this->mapper->valueExistsForField(200, 'value_string', 'SN-1', $a->getId()));
		// The soft-deleted holder of 'SN-2' does not count.
		$this->assertFalse($this->mapper->valueExistsForField(200, 'value_string', 'SN-2', $b->getId()));
		// Unknown column short-circuits to false.
		$this->assertFalse($this->mapper->valueExistsForField(200, 'value_bogus', 'SN-1', $b->getId()));
	}

	public function testDeleteByFieldAndByFieldIds(): void {
		$rec = $this->record();
		$this->mapper->insertValue($rec->getId(), 301, 'value_string', 'a');
		$this->mapper->insertValue($rec->getId(), 302, 'value_string', 'b');
		$this->mapper->insertValue($rec->getId(), 303, 'value_string', 'c');

		$this->mapper->deleteByField(301);
		$this->mapper->deleteByFieldIds([302]);
		$this->mapper->deleteByFieldIds([]); // no-op, must not wipe everything

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([303], array_map(static fn ($r) => (int)$r['field_id'], $rows));
	}

	public function testFindByRecordIdsGroupsAndHandlesEmpty(): void {
		$a = $this->record(1);
		$b = $this->record(2);
		$this->mapper->insertValue($a->getId(), 401, 'value_string', 'a');
		$this->mapper->insertValue($b->getId(), 401, 'value_string', 'b');

		$grouped = $this->mapper->findByRecordIds([$a->getId(), $b->getId()]);
		$this->assertArrayHasKey($a->getId(), $grouped);
		$this->assertArrayHasKey($b->getId(), $grouped);
		$this->assertSame([], $this->mapper->findByRecordIds([]));
	}
}
