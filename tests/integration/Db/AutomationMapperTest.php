<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Db\AutomationLog;
use OCA\Dataforms\Db\AutomationLogMapper;
use OCA\Dataforms\Db\AutomationMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * AutomationMapper + AutomationLogMapper against the real migrated schema: the
 * findActive(register, trigger) query that the engine relies on (enabled-only,
 * by trigger), and the activity-log read + retention sweep (AUT-23). Each test
 * runs in a rolled-back transaction on unused register ids.
 */
class AutomationMapperTest extends TestCase {
	private const REG = 998001;
	private const REG2 = 998002;

	private IDBConnection $db;
	private AutomationMapper $mapper;
	private AutomationLogMapper $logMapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new AutomationMapper($this->db);
		$this->logMapper = new AutomationLogMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function automation(string $trigger, string $type, bool $enabled, int $registerId = self::REG): Automation {
		$a = new Automation();
		$a->setRegisterId($registerId);
		$a->setName(ucfirst($type));
		$a->setTrigger($trigger);
		$a->setActionType($type);
		$a->setActionConfig('{}');
		$a->setEnabled($enabled);
		$a->setCreated(1_690_000_000);
		$a->setUpdated(1_690_000_000);
		return $this->mapper->insert($a);
	}

	private function log(string $status, int $created, int $registerId = self::REG): AutomationLog {
		$l = new AutomationLog();
		$l->setRegisterId($registerId);
		$l->setAutomationName('Auto');
		$l->setActionType('webhook');
		$l->setTrigger('create');
		$l->setStatus($status);
		$l->setCreated($created);
		return $this->logMapper->insert($l);
	}

	public function testFindActiveIsEnabledOnlyByTrigger(): void {
		$this->automation('create', 'notify', true);
		$this->automation('create', 'webhook', false);  // disabled — excluded
		$this->automation('update', 'email', true);      // different trigger — excluded
		$this->automation('create', 'notify', true, self::REG2); // other register

		$active = $this->mapper->findActive(self::REG, 'create');
		$this->assertCount(1, $active);
		$this->assertSame('notify', $active[0]->getActionType());
	}

	public function testFindByRegisterAndPurge(): void {
		$this->automation('create', 'notify', true);
		$this->automation('update', 'email', false);
		$this->assertCount(2, $this->mapper->findByRegister(self::REG));

		$this->mapper->deleteByRegister(self::REG);
		$this->assertSame([], $this->mapper->findByRegister(self::REG));
	}

	public function testActivityLogIsMostRecentFirstAndRetentionSweeps(): void {
		$this->log('ok', 1_000);
		$this->log('error', 3_000);
		$this->log('ok', 2_000);

		$recent = $this->logMapper->findByRegister(self::REG);
		$this->assertSame([3_000, 2_000, 1_000], array_map(static fn (AutomationLog $l) => $l->getCreated(), $recent));

		// Retention sweep: drop entries older than the cutoff.
		$deleted = $this->logMapper->deleteOlderThan(2_500);
		$this->assertSame(2, $deleted); // the 1000 + 2000 entries
		$this->assertCount(1, $this->logMapper->findByRegister(self::REG));

		$this->logMapper->deleteByRegister(self::REG);
		$this->assertSame([], $this->logMapper->findByRegister(self::REG));
	}
}
