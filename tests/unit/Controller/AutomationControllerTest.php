<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\AutomationController;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\AutomationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AutomationControllerTest extends TestCase {
	private AutomationService $service;
	private AutomationLogService $logService;
	private AutomationController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(AutomationService::class);
		$this->logService = $this->createMock(AutomationLogService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new AutomationController($this->createMock(IRequest::class), $this->service, $this->logService, $session);
	}

	public function testActionsReportsAvailableActionsAndServiceAccounts(): void {
		$this->service->method('availableActionTypes')->willReturn(['notify', 'email']);
		$this->service->method('serviceAccounts')->willReturn([['id' => 1]]);
		$this->assertSame(['actions' => ['notify', 'email'], 'serviceAccounts' => [['id' => 1]]], $this->controller->actions()->getData());
	}

	public function testIndexLists(): void {
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testLogLists(): void {
		$this->logService->expects($this->once())->method('listForRegister')
			->with('alice', 2, 50)->willReturn([['status' => 'ok']]);
		$this->assertSame([['status' => 'ok']], $this->controller->log(2, 50)->getData());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, [
				'name' => 'A', 'trigger' => 'create', 'actionType' => 'notify',
				'condition' => null, 'actionConfig' => [], 'enabled' => true,
			])->willReturn(['id' => 9]);
		$res = $this->controller->create(2, 'A', 'create', 'notify');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('create')->willThrowException(new ValidationException('Unknown trigger: x'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(2, 'A', 'x', 'notify')->getStatus());
	}

	public function testUpdateAppliesChanges(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['enabled' => false])->willReturn(['id' => 9]);
		$this->controller->update(9, ['enabled' => false]);
	}

	public function testDestroyMapsForbidden(): void {
		$this->service->method('delete')->willThrowException(new ForbiddenException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroy(9)->getStatus());
	}

	public function testDestroyReturnsEmpty(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 9);
		$this->assertSame([], $this->controller->destroy(9)->getData());
	}
}
