<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RegisterMapper against the REAL database, with the schema built by the app's
 * own migrations — the highest-risk (portable EAV) SQL, exercised on whichever
 * engine the CI matrix runs (SQLite/MySQL/PostgreSQL). Each test runs inside a
 * transaction that is rolled back, so the instance's data is never touched.
 */
class RegisterMapperTest extends TestCase {
	private IDBConnection $db;
	private RegisterMapper $mapper;
	private ShareMapper $shareMapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RegisterMapper($this->db);
		$this->shareMapper = new ShareMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function makeRegister(string $owner, string $title, ?int $deletedAt = null): Register {
		$r = new Register();
		$r->setTitle($title);
		$r->setOwner($owner);
		$r->setIcon('table');
		$r->setColor('#0082c9');
		$r->setCreated(1000);
		$r->setUpdated(1000);
		$r->setDeletedAt($deletedAt);
		return $this->mapper->insert($r);
	}

	private function share(int $registerId, int $type, string $with, int $perms = 1): void {
		$s = new Share();
		$s->setRegisterId($registerId);
		$s->setShareType($type);
		$s->setShareWith($with);
		$s->setPermissions($perms);
		$this->shareMapper->insert($s);
	}

	public function testInsertThenFindReturnsTheStoredRegister(): void {
		$saved = $this->makeRegister('alice', 'Fines');
		$this->assertGreaterThan(0, $saved->getId());

		$found = $this->mapper->find($saved->getId());
		$this->assertSame('Fines', $found->getTitle());
		$this->assertSame('alice', $found->getOwner());
	}

	public function testFindExcludesSoftDeletedRegisters(): void {
		$saved = $this->makeRegister('alice', 'Gone');
		$saved->setDeletedAt(1234);
		$this->mapper->update($saved);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find($saved->getId());
	}

	public function testFindAllForUserCoversOwnerUserShareAndGroupShare(): void {
		$own = $this->makeRegister('alice', 'Alice own');
		$userShared = $this->makeRegister('carol', 'Shared to bob');
		$this->share($userShared->getId(), Share::TYPE_USER, 'bob');
		$groupShared = $this->makeRegister('dave', 'Shared to team');
		$this->share($groupShared->getId(), Share::TYPE_GROUP, 'team');
		$invisible = $this->makeRegister('erin', 'Not shared to bob');

		$bobIds = array_map(static fn (Register $r) => $r->getId(), $this->mapper->findAllForUser('bob', ['team']));
		$this->assertContains($userShared->getId(), $bobIds);
		$this->assertContains($groupShared->getId(), $bobIds);
		$this->assertNotContains($invisible->getId(), $bobIds);
		$this->assertNotContains($own->getId(), $bobIds);

		$aliceIds = array_map(static fn (Register $r) => $r->getId(), $this->mapper->findAllForUser('alice', []));
		$this->assertContains($own->getId(), $aliceIds);
	}

	public function testFindAllForUserExcludesSoftDeleted(): void {
		$live = $this->makeRegister('alice', 'Live');
		$dead = $this->makeRegister('alice', 'Dead', 999);

		$ids = array_map(static fn (Register $r) => $r->getId(), $this->mapper->findAllForUser('alice', []));
		$this->assertContains($live->getId(), $ids);
		$this->assertNotContains($dead->getId(), $ids);
	}

	public function testFindSoftDeletedBeforeReturnsOnlyExpiredIds(): void {
		$old = $this->makeRegister('alice', 'Old deleted', 100);
		$recent = $this->makeRegister('alice', 'Recent deleted', 9000);
		$live = $this->makeRegister('alice', 'Live');

		$ids = $this->mapper->findSoftDeletedBefore(5000);
		$this->assertContains($old->getId(), $ids);
		$this->assertNotContains($recent->getId(), $ids);
		$this->assertNotContains($live->getId(), $ids);
	}

	public function testDeleteByIdHardDeletesTheRow(): void {
		$saved = $this->makeRegister('alice', 'Doomed');
		$this->mapper->deleteById($saved->getId());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find($saved->getId());
	}
}
