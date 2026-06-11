<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Db\AutomationLog;
use OCA\Dataforms\Db\AutomationLogMapper;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The automation activity log: records each run's outcome (best-effort, never
 * breaking the automation), reads it manager-gated, and trims old rows.
 */
class AutomationLogServiceTest extends TestCase {

	private function automation(): Automation {
		$a = new Automation();
		$a->setId(7);
		$a->setName('Notify reviewer');
		$a->setActionType('notify');
		$a->setTrigger('create');
		return $a;
	}

	public function testRecordPersistsTheOutcome(): void {
		$mapper = $this->createMock(AutomationLogMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		$captured = null;
		$mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (AutomationLog $l) use (&$captured): AutomationLog {
				$captured = $l;
				return $l;
			});

		$svc = new AutomationLogService($mapper, $this->createMock(RegisterService::class), $time, $this->createMock(LoggerInterface::class));
		$svc->record($this->automation(), 5, 42, AutomationLogService::STATUS_ERROR, 'boom');

		$this->assertSame(5, $captured->getRegisterId());
		$this->assertSame(42, $captured->getRecordId());
		$this->assertSame(7, $captured->getAutomationId());
		$this->assertSame('Notify reviewer', $captured->getAutomationName());
		$this->assertSame('notify', $captured->getActionType());
		$this->assertSame('create', $captured->getTrigger());
		$this->assertSame('error', $captured->getStatus());
		$this->assertSame('boom', $captured->getMessage());
		$this->assertSame(1000, $captured->getCreated());
	}

	public function testRecordIsBestEffortAndSwallowsFailures(): void {
		$mapper = $this->createMock(AutomationLogMapper::class);
		$mapper->method('insert')->willThrowException(new \RuntimeException('db down'));
		$svc = new AutomationLogService($mapper, $this->createMock(RegisterService::class), $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));

		// Must not throw — a logging failure can never break an automation.
		$svc->record($this->automation(), 5, 42, AutomationLogService::STATUS_OK);
		$this->addToAssertionCount(1);
	}

	public function testUnknownStatusNormalisesToOk(): void {
		$mapper = $this->createMock(AutomationLogMapper::class);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (AutomationLog $l) use (&$captured): AutomationLog {
			$captured = $l;
			return $l;
		});
		$svc = new AutomationLogService($mapper, $this->createMock(RegisterService::class), $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		$svc->record($this->automation(), 1, null, 'weird-status');
		$this->assertSame('ok', $captured->getStatus());
		$this->assertNull($captured->getRecordId());
	}

	public function testListForRegisterIsManagerGated(): void {
		$registers = $this->createMock(RegisterService::class);
		$registers->expects($this->once())->method('findManageable')->with('alice', 5);

		$entry = $this->automation();
		$logRow = new AutomationLog();
		$logRow->setRegisterId(5);
		$logRow->setStatus('ok');
		$mapper = $this->createMock(AutomationLogMapper::class);
		$mapper->method('findByRegister')->with(5, 100)->willReturn([$logRow]);

		$svc = new AutomationLogService($mapper, $registers, $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		$out = $svc->listForRegister('alice', 5, 100);
		$this->assertCount(1, $out);
		$this->assertSame('ok', $out[0]['status']);
	}

	public function testPurgeExpiredUsesRetentionCutoff(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(100_000_000);
		$mapper = $this->createMock(AutomationLogMapper::class);
		$mapper->expects($this->once())->method('deleteOlderThan')
			->with(100_000_000 - AutomationLogService::RETENTION_DAYS * 86400)
			->willReturn(4);

		$svc = new AutomationLogService($mapper, $this->createMock(RegisterService::class), $time, $this->createMock(LoggerInterface::class));
		$this->assertSame(4, $svc->purgeExpired());
	}
}
