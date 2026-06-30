<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\BackgroundJob;

use OCA\Dataforms\BackgroundJob\PurgeDeletedRegistersJob;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\RegisterPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The daily retention job: runs both sweeps (registers + automation log),
 * logs what it reclaimed, and never lets a failure escape the cron worker.
 */
class PurgeDeletedRegistersJobTest extends TestCase {
	private $purge;
	private $logService;
	private $logger;
	private PurgeDeletedRegistersJob $job;

	protected function setUp(): void {
		$this->purge = $this->createMock(RegisterPurgeService::class);
		$this->logService = $this->createMock(AutomationLogService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job = new PurgeDeletedRegistersJob(
			$this->createMock(ITimeFactory::class),
			$this->purge,
			$this->logService,
			$this->logger,
		);
	}

	private function invokeRun(): void {
		$m = new ReflectionMethod(PurgeDeletedRegistersJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($this->job, null);
	}

	public function testLogsBothSweepsWhenSomethingWasReclaimed(): void {
		$this->purge->method('purgeExpired')->willReturn(2);
		$this->logService->method('purgeExpired')->willReturn(5);
		$this->logger->expects($this->exactly(2))->method('info');
		$this->logger->expects($this->never())->method('error');
		$this->invokeRun();
	}

	public function testStaysQuietWhenNothingWasReclaimed(): void {
		$this->purge->method('purgeExpired')->willReturn(0);
		$this->logService->method('purgeExpired')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->invokeRun();
	}

	public function testSwallowsAndLogsAFailure(): void {
		$this->purge->method('purgeExpired')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');
		$this->invokeRun(); // must not throw
		$this->addToAssertionCount(1);
	}
}
