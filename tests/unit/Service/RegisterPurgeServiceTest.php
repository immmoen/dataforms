<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\AutomationLogMapper;
use OCA\Dataforms\Db\AutomationMapper;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\FormMapper;
use OCA\Dataforms\Db\HistoryMapper;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\RuleMapper;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Service\RegisterPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Retention purge: hard-deletes a soft-deleted register and ALL its child rows
 * atomically, and sweeps every register past the retention window.
 */
class RegisterPurgeServiceTest extends TestCase {
	private $registerMapper;
	private $fieldMapper;
	private $refMapper;
	private $valueMapper;
	private $fileMapper;
	private $db;
	private $time;
	private $logger;
	private RegisterPurgeService $service;

	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->fieldMapper = $this->createMock(FieldMapper::class);
		$this->refMapper = $this->createMock(RecordRefMapper::class);
		$this->valueMapper = $this->createMock(RecordValueMapper::class);
		$this->fileMapper = $this->createMock(RecordFileMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new RegisterPurgeService(
			$this->registerMapper,
			$this->fieldMapper,
			$this->createMock(RecordMapper::class),
			$this->valueMapper,
			$this->fileMapper,
			$this->refMapper,
			$this->createMock(RuleMapper::class),
			$this->createMock(AutomationMapper::class),
			$this->createMock(AutomationLogMapper::class),
			$this->createMock(HistoryMapper::class),
			$this->createMock(ShareMapper::class),
			$this->createMock(ViewMapper::class),
			$this->createMock(FormMapper::class),
			$this->db,
			$this->time,
			$this->logger,
		);
	}

	public function testPurgeDeletesChildrenByFieldIdsAndCommits(): void {
		$this->fieldMapper->method('idsForRegister')->with(5)->willReturn([10, 11]);
		$this->refMapper->expects($this->once())->method('deleteByTargetRegister')->with(5);
		$this->valueMapper->expects($this->once())->method('deleteByFieldIds')->with([10, 11]);
		$this->fileMapper->expects($this->once())->method('deleteByFieldIds')->with([10, 11]);
		$this->registerMapper->expects($this->once())->method('deleteById')->with(5);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service->purge(5);
	}

	public function testPurgeRollsBackAndRethrowsOnFailure(): void {
		$this->fieldMapper->method('idsForRegister')->willReturn([]);
		$this->registerMapper->method('deleteById')->willThrowException(new \RuntimeException('db down'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->service->purge(5);
	}

	public function testPurgeExpiredUsesTheRetentionCutoffAndCountsPurged(): void {
		$this->time->method('getTime')->willReturn(1_000_000);
		$cutoff = 1_000_000 - RegisterPurgeService::RETENTION_DAYS * 86400;
		$this->registerMapper->expects($this->once())->method('findSoftDeletedBefore')
			->with($cutoff)->willReturn([1, 2]);
		$this->fieldMapper->method('idsForRegister')->willReturn([]);

		$this->assertSame(2, $this->service->purgeExpired());
	}

	public function testPurgeExpiredSkipsAFailingRegisterAndContinues(): void {
		$this->time->method('getTime')->willReturn(1_000_000);
		$this->registerMapper->method('findSoftDeletedBefore')->willReturn([1, 2]);
		// purge(1) throws (idsForRegister fails for it), purge(2) succeeds.
		$this->fieldMapper->method('idsForRegister')->willReturnCallback(function (int $id): array {
			if ($id === 1) {
				throw new \RuntimeException('boom');
			}
			return [];
		});
		$this->logger->expects($this->once())->method('error');

		$this->assertSame(1, $this->service->purgeExpired());
	}
}
