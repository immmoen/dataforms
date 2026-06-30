<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Service\RecordService;
use OCA\Dataforms\Service\RegisterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * requireOwnOrManage(): a record may be changed by its creator, or by a
 * register manager (the shared isManager capability); otherwise Forbidden.
 * Exercised in isolation — the rule only touches the injected RegisterService.
 */
class RecordServicePermissionTest extends TestCase {
	private function service(RegisterService $registerService): RecordService {
		$svc = (new ReflectionClass(RecordService::class))->newInstanceWithoutConstructor();
		$prop = (new ReflectionClass(RecordService::class))->getProperty('registerService');
		$prop->setAccessible(true);
		$prop->setValue($svc, $registerService);
		return $svc;
	}

	private function invoke(RecordService $svc, string $userId, string $creator): void {
		$record = new Record();
		$record->setCreatedBy($creator);
		$m = new ReflectionMethod(RecordService::class, 'requireOwnOrManage');
		$m->setAccessible(true);
		$m->invoke($svc, $userId, $record, new Register());
	}

	public function testCreatorMayChangeOwnRecord(): void {
		$rs = $this->createMock(RegisterService::class);
		$rs->expects($this->never())->method('isManager');
		$this->invoke($this->service($rs), 'alice', 'alice');
		$this->addToAssertionCount(1); // no exception
	}

	public function testManagerMayChangeOthersRecord(): void {
		$rs = $this->createMock(RegisterService::class);
		$rs->method('isManager')->willReturn(true);
		$this->invoke($this->service($rs), 'bob', 'alice');
		$this->addToAssertionCount(1); // no exception
	}

	public function testNonManagerCannotChangeOthersRecord(): void {
		$rs = $this->createMock(RegisterService::class);
		$rs->method('isManager')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->invoke($this->service($rs), 'bob', 'alice');
	}
}
