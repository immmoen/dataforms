<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Service\FieldService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * FieldMapper against the real migrated schema (per CI database engine). Covers
 * position ordering, soft-delete exclusion from the active list, and the
 * machine-name tombstone (a retired name stays reserved — audit M2). Each test
 * is wrapped in a rolled-back transaction, on an unused register id.
 */
class FieldMapperTest extends TestCase {
	private const REG = 990001;

	private IDBConnection $db;
	private FieldMapper $mapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new FieldMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function insert(string $machineName, string $type = 'text', int $position = 0, ?int $deletedAt = null): Field {
		$f = new Field();
		$f->setRegisterId(self::REG);
		$f->setMachineName($machineName);
		$f->setLabel(ucfirst($machineName));
		$f->setType($type);
		$f->setConfig('{}');
		$f->setPosition($position);
		$f->setMandatory(false);
		$f->setIsUnique(false);
		$f->setDeletedAt($deletedAt);
		return $this->mapper->insert($f);
	}

	public function testInsertAndFindRoundTripsEveryDeclaredType(): void {
		foreach (FieldService::TYPES as $i => $type) {
			$saved = $this->insert('f_' . $i, $type, $i);
			$found = $this->mapper->find($saved->getId());
			$this->assertSame($type, $found->getType());
			$this->assertSame(self::REG, $found->getRegisterId());
		}
		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(-1);
	}

	public function testFindByRegisterIsActiveOnlyAndOrderedByPosition(): void {
		$this->insert('b', 'text', 2);
		$this->insert('a', 'text', 1);
		$this->insert('gone', 'text', 0, 12345); // soft-deleted

		$active = $this->mapper->findByRegister(self::REG);
		$names = array_map(static fn (Field $f) => $f->getMachineName(), $active);
		$this->assertSame(['a', 'b'], $names); // ordered by position, tombstone excluded
	}

	public function testMaxPositionIgnoresDeletedAndReturnsMinusOneWhenEmpty(): void {
		$this->assertSame(-1, $this->mapper->maxPosition(self::REG));
		$this->insert('a', 'text', 5);
		$this->insert('gone', 'text', 9, 12345); // deleted — not counted
		$this->assertSame(5, $this->mapper->maxPosition(self::REG));
	}

	public function testMachineNameTombstoneKeepsTheNameReserved(): void {
		$this->insert('status', 'text', 0, 999); // retired (soft-deleted)
		// Still "exists" so the name is never reissued (audit M2).
		$this->assertTrue($this->mapper->machineNameExists(self::REG, 'status'));
		$this->assertFalse($this->mapper->machineNameExists(self::REG, 'never_used'));
	}

	public function testIdsForRegisterIncludesDeletedAndDeleteByRegisterRemovesAll(): void {
		$live = $this->insert('live', 'text', 0);
		$dead = $this->insert('dead', 'text', 1, 999);
		$ids = $this->mapper->idsForRegister(self::REG);
		$this->assertContains($live->getId(), $ids);
		$this->assertContains($dead->getId(), $ids);

		$this->mapper->deleteByRegister(self::REG);
		$this->assertSame([], $this->mapper->idsForRegister(self::REG));
	}
}
