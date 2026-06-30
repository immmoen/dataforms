<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Rule;
use OCA\Dataforms\Db\RuleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * RuleMapper against the real migrated schema (per CI database engine):
 * insert/find round-trip, position ordering within a register, and the
 * register-scoped purge. Each test runs in a rolled-back transaction on an
 * unused register id.
 */
class RuleMapperTest extends TestCase {
	private const REG = 995001;
	private const REG2 = 995002;

	private IDBConnection $db;
	private RuleMapper $mapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new RuleMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function insert(int $registerId, string $effect, string $target, int $position = 0, bool $enabled = true): Rule {
		$r = new Rule();
		$r->setRegisterId($registerId);
		$r->setEffect($effect);
		$r->setTarget($target);
		$r->setDefinition(json_encode(['conditions' => null, 'value' => null, 'expression' => 'a * b', 'validation' => null]));
		$r->setPosition($position);
		$r->setEnabled($enabled);
		return $this->mapper->insert($r);
	}

	public function testInsertAndFindRoundTrip(): void {
		$saved = $this->insert(self::REG, 'compute', 'risk', 0);
		$found = $this->mapper->find($saved->getId());
		$this->assertSame('compute', $found->getEffect());
		$this->assertSame('risk', $found->getTarget());
		$this->assertSame('a * b', $found->jsonSerialize()['expression']);
		$this->assertTrue($found->jsonSerialize()['enabled']);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(-1);
	}

	public function testFindByRegisterIsOrderedByPositionThenId(): void {
		$this->insert(self::REG, 'show', 'c', 2);
		$this->insert(self::REG, 'show', 'a', 0);
		$this->insert(self::REG, 'show', 'b', 1);
		$this->insert(self::REG2, 'show', 'other', 0); // different register, excluded

		$targets = array_map(static fn (Rule $r) => $r->getTarget(), $this->mapper->findByRegister(self::REG));
		$this->assertSame(['a', 'b', 'c'], $targets);
	}

	public function testDeleteByRegisterRemovesOnlyThatRegistersRules(): void {
		$this->insert(self::REG, 'show', 'a');
		$this->insert(self::REG, 'require', 'b');
		$keep = $this->insert(self::REG2, 'show', 'keep');

		$this->mapper->deleteByRegister(self::REG);

		$this->assertSame([], $this->mapper->findByRegister(self::REG));
		$this->assertCount(1, $this->mapper->findByRegister(self::REG2));
		$this->assertSame($keep->getId(), $this->mapper->find($keep->getId())->getId());
	}
}
