<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RecordFileMapper against the real migrated schema. Covers position-ordered
 * file-attachment rows, the per-record/field clear, and the field-id purge
 * helpers. File contents live in Nextcloud Files; this table only references
 * them by id.
 */
class RecordFileMapperTest extends TestCase {
	private const REG = 994001;

	private IDBConnection $db;
	private RecordFileMapper $mapper;
	private RecordMapper $records;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RecordFileMapper($this->db);
		$this->records = new RecordMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function record(): Record {
		$r = new Record();
		$r->setRegisterId(self::REG);
		$r->setOwner('alice');
		$r->setCreatedBy('alice');
		$r->setCreated(1_690_000_000);
		$r->setUpdated(1_690_000_000);
		$r->setSeq(1);
		return $this->records->insert($r);
	}

	public function testInsertAndFindByRecordIdsIsPositionOrdered(): void {
		$rec = $this->record();
		$this->mapper->insertFile($rec->getId(), 10, 5001, 1);
		$this->mapper->insertFile($rec->getId(), 10, 5000, 0);

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([5000, 5001], array_map(static fn ($r) => $r['file_id'], $rows));
		$this->assertSame([], $this->mapper->findByRecordIds([]));
	}

	public function testDeleteForRecordField(): void {
		$rec = $this->record();
		$this->mapper->insertFile($rec->getId(), 10, 5000, 0);
		$this->mapper->insertFile($rec->getId(), 11, 6000, 0);

		$this->mapper->deleteForRecordField($rec->getId(), 10);
		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([11], array_map(static fn ($r) => (int)$r['field_id'], $rows));
	}

	public function testDeleteForFieldAndByFieldIds(): void {
		$rec = $this->record();
		$this->mapper->insertFile($rec->getId(), 10, 5000, 0);
		$this->mapper->insertFile($rec->getId(), 11, 6000, 0);
		$this->mapper->insertFile($rec->getId(), 12, 7000, 0);

		$this->mapper->deleteForField(10);
		$this->mapper->deleteByFieldIds([11]);
		$this->mapper->deleteByFieldIds([]); // no-op

		$rows = $this->mapper->findByRecordIds([$rec->getId()])[$rec->getId()];
		$this->assertSame([12], array_map(static fn ($r) => (int)$r['field_id'], $rows));
	}
}
