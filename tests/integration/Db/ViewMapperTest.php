<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\View;
use OCA\Dataforms\Db\ViewMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * ViewMapper against the real migrated schema (per CI database engine). The
 * visibility query is the interesting bit: a user sees their OWN views plus any
 * SHARED view, ordered by title — the portable owner-OR-shared predicate that
 * backs private/shared saved views (VW-13/VW-14). Each test runs in a
 * rolled-back transaction on an unused register id.
 */
class ViewMapperTest extends TestCase {
	private const REG = 996001;
	private const REG2 = 996002;

	private IDBConnection $db;
	private ViewMapper $mapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new ViewMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function insert(int $registerId, string $title, string $owner, bool $shared): View {
		$v = new View();
		$v->setRegisterId($registerId);
		$v->setTitle($title);
		$v->setOwner($owner);
		$v->setShared($shared);
		$v->setDefinition('{"columns":[],"filters":[],"sort":"updated","direction":"DESC","search":""}');
		$v->setCreated(1_690_000_000);
		$v->setUpdated(1_690_000_000);
		return $this->mapper->insert($v);
	}

	public function testFindRoundTrips(): void {
		$saved = $this->insert(self::REG, 'Mine', 'alice', false);
		$found = $this->mapper->find($saved->getId());
		$this->assertSame('Mine', $found->getTitle());
		$this->assertFalse((bool)$found->getShared());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(-1);
	}

	public function testFindForRegisterReturnsOwnPlusSharedOrderedByTitle(): void {
		$this->insert(self::REG, 'Zed (mine)', 'alice', false);
		$this->insert(self::REG, 'Anna (shared by bob)', 'bob', true);
		$this->insert(self::REG, 'Bob private', 'bob', false);     // hidden from alice
		$this->insert(self::REG2, 'Other register', 'alice', true); // different register

		$titles = array_map(static fn (View $v) => $v->getTitle(), $this->mapper->findForRegister(self::REG, 'alice'));
		// Alice's own + bob's shared, alphabetical by title; bob's private excluded.
		$this->assertSame(['Anna (shared by bob)', 'Zed (mine)'], $titles);
	}

	public function testDeleteByRegisterRemovesOnlyThatRegistersViews(): void {
		$this->insert(self::REG, 'A', 'alice', true);
		$this->insert(self::REG, 'B', 'bob', false);
		$keep = $this->insert(self::REG2, 'Keep', 'alice', false);

		$this->mapper->deleteByRegister(self::REG);

		$this->assertSame([], $this->mapper->findForRegister(self::REG, 'alice'));
		$this->assertSame($keep->getId(), $this->mapper->find($keep->getId())->getId());
	}
}
