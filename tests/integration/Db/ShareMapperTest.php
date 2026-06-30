<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * ShareMapper against the real migrated schema (per CI database engine). The
 * interesting query is permissionsFor(): the OR-union of the bits a user gets
 * directly AND through their groups — the access-control predicate that backs
 * read/write/manage. Each test runs in a rolled-back transaction on unused ids.
 */
class ShareMapperTest extends TestCase {
	private const REG = 997001;

	private IDBConnection $db;
	private ShareMapper $mapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new ShareMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function insert(int $type, string $with, int $permissions, int $registerId = self::REG): Share {
		$s = new Share();
		$s->setRegisterId($registerId);
		$s->setShareType($type);
		$s->setShareWith($with);
		$s->setPermissions($permissions);
		$s->setCreated(1_690_000_000);
		return $this->mapper->insert($s);
	}

	public function testPermissionsForUnionsUserAndGroupBits(): void {
		// bob: READ direct; group "staff": WRITE; group "leads": MANAGE.
		$this->insert(Share::TYPE_USER, 'bob', Share::PERMISSION_READ);
		$this->insert(Share::TYPE_GROUP, 'staff', Share::PERMISSION_READ | Share::PERMISSION_WRITE);
		$this->insert(Share::TYPE_GROUP, 'leads', Share::PERMISSION_READ | Share::PERMISSION_MANAGE);

		// bob in "staff" → READ | WRITE.
		$this->assertSame(
			Share::PERMISSION_READ | Share::PERMISSION_WRITE,
			$this->mapper->permissionsFor(self::REG, 'bob', ['staff']),
		);
		// bob in both groups → READ | WRITE | MANAGE (the OR-union).
		$this->assertSame(
			Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE,
			$this->mapper->permissionsFor(self::REG, 'bob', ['staff', 'leads']),
		);
		// carol with no direct/group share → 0.
		$this->assertSame(0, $this->mapper->permissionsFor(self::REG, 'carol', ['other']));
	}

	public function testFindRoundTripAndByRegisterAndExisting(): void {
		$s = $this->insert(Share::TYPE_USER, 'bob', Share::PERMISSION_READ);
		$this->assertSame('bob', $this->mapper->find($s->getId())->getShareWith());
		$this->assertCount(1, $this->mapper->findByRegister(self::REG));

		$this->assertNotNull($this->mapper->findExisting(self::REG, Share::TYPE_USER, 'bob'));
		$this->assertNull($this->mapper->findExisting(self::REG, Share::TYPE_USER, 'nobody'));
	}

	public function testDeleteByRegisterRemovesAll(): void {
		$this->insert(Share::TYPE_USER, 'bob', Share::PERMISSION_READ);
		$this->insert(Share::TYPE_GROUP, 'staff', Share::PERMISSION_WRITE);
		$this->mapper->deleteByRegister(self::REG);
		$this->assertSame([], $this->mapper->findByRegister(self::REG));
		$this->assertSame(0, $this->mapper->permissionsFor(self::REG, 'bob', ['staff']));
	}
}
