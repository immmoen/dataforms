<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\View;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\ViewService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * View edit-authorisation: the owner, or a register manager (the shared
 * isManager capability), may change/delete a view; nobody else.
 */
class ViewServiceTest extends TestCase {
	private ViewMapper $mapper;
	private RegisterService $registerService;
	private ViewService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ViewMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->service = new ViewService($this->mapper, $this->registerService, $this->createMock(ITimeFactory::class));
	}

	private function view(string $owner): View {
		$v = new View();
		$v->setId(3);
		$v->setRegisterId(5);
		$v->setOwner($owner);
		return $v;
	}

	public function testOwnerMayDeleteTheirView(): void {
		$this->mapper->method('find')->willReturn($this->view('alice'));
		$this->registerService->expects($this->never())->method('isManager');
		$this->mapper->expects($this->once())->method('delete');
		$this->service->delete('alice', 3);
	}

	public function testManagerMayDeleteAnotherUsersView(): void {
		$this->mapper->method('find')->willReturn($this->view('alice'));
		$this->registerService->method('find')->willReturn(new Register());
		$this->registerService->method('isManager')->with($this->isInstanceOf(Register::class), 'bob')->willReturn(true);
		$this->mapper->expects($this->once())->method('delete');
		$this->service->delete('bob', 3);
	}

	public function testNonManagerCannotDeleteAnotherUsersView(): void {
		$this->mapper->method('find')->willReturn($this->view('alice'));
		$this->registerService->method('find')->willReturn(new Register());
		$this->registerService->method('isManager')->willReturn(false);
		$this->expectException(ForbiddenException::class);
		$this->service->delete('bob', 3);
	}
}
