<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Integration\Db;

use OCA\Dataforms\Db\History;
use OCA\Dataforms\Db\HistoryMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * HistoryMapper against the real migrated schema (AUD-01/02/03/04): per-record
 * audit entries are read most-recent-first, and the register purge removes the
 * trail. Each test runs in a rolled-back transaction on unused ids.
 */
class HistoryMapperTest extends TestCase {
	private const REG = 999101;
	private const REC = 770001;
	private const REC2 = 770002;

	private IDBConnection $db;
	private HistoryMapper $mapper;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->mapper = new HistoryMapper($this->db);
		$this->db->beginTransaction();
	}

	protected function tearDown(): void {
		$this->db->rollBack();
	}

	private function entry(int $recordId, string $action, string $user, int $created, ?array $detail = null, int $registerId = self::REG): History {
		$h = new History();
		$h->setRegisterId($registerId);
		$h->setRecordId($recordId);
		$h->setUserId($user);
		$h->setAction($action);
		$h->setSummary(ucfirst($action) . ' record');
		$h->setDetail($detail === null ? null : json_encode($detail));
		$h->setCreated($created);
		return $this->mapper->insert($h);
	}

	public function testFindByRecordIsMostRecentFirstWithAuthorAndDetail(): void {
		$this->entry(self::REC, 'create', 'alice', 1_000);
		$this->entry(self::REC, 'update', 'bob', 3_000, ['fields' => ['Title']]);
		$this->entry(self::REC, 'delete', 'alice', 2_000);
		$this->entry(self::REC2, 'create', 'carol', 5_000); // different record — excluded

		$trail = $this->mapper->findByRecord(self::REC);
		$this->assertSame(['update', 'delete', 'create'], array_map(static fn (History $h) => $h->getAction(), $trail));
		// The update keeps its author and change detail.
		$this->assertSame('bob', $trail[0]->getUserId());
		$this->assertSame(['fields' => ['Title']], json_decode((string)$trail[0]->getDetail(), true));
		// The limit is honoured.
		$this->assertCount(2, $this->mapper->findByRecord(self::REC, 2));
	}

	public function testDeleteByRegisterPurgesTheTrail(): void {
		$this->entry(self::REC, 'create', 'alice', 1_000);
		$keep = $this->entry(self::REC2, 'create', 'alice', 1_000, null, self::REG + 1);

		$this->mapper->deleteByRegister(self::REG);
		$this->assertSame([], $this->mapper->findByRecord(self::REC));
		$this->assertCount(1, $this->mapper->findByRecord(self::REC2)); // other register untouched
		$this->assertSame($keep->getId(), $this->mapper->findByRecord(self::REC2)[0]->getId());
	}
}
