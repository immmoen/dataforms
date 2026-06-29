<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\FieldController;
use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FieldService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FieldControllerTest extends TestCase {
	private FieldService $service;
	private ISession $appSession;
	private FieldController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(FieldService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->appSession = $this->createMock(ISession::class);
		$this->controller = new FieldController($this->createMock(IRequest::class), $this->service, $session, $this->appSession);
	}

	public function testIndexReleasesSessionAndLists(): void {
		$this->appSession->expects($this->once())->method('close');
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testCreatePassesTheFieldDefinitionAndReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, [
				'label' => 'Name', 'type' => 'text', 'machineName' => 'name',
				'config' => ['maxLength' => 5], 'mandatory' => true, 'unique' => false, 'default' => 'x',
			])->willReturn(new Field());
		$res = $this->controller->create(2, 'Name', 'text', 'name', ['maxLength' => 5], true, false, 'x');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('create')->willThrowException(new ValidationException('Label is required'));
		$res = $this->controller->create(2);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'Label is required'], $res->getData());
	}

	public function testUpdateBuildsOnlyPresentChanges(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['label' => 'N', 'mandatory' => true])->willReturn(new Field());
		$this->controller->update(9, 'N', null, true);
	}

	public function testUpdateBuildsConfigUniqueAndDefaultChanges(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['config' => ['min' => 1], 'unique' => true, 'default' => '0'])->willReturn(new Field());
		$this->controller->update(9, null, ['min' => 1], null, true, '0');
	}

	public function testDestroyReturnsEmptyOnSuccess(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 9);
		$this->assertSame([], $this->controller->destroy(9)->getData());
	}

	public function testDestroyMapsForbidden(): void {
		$this->service->method('delete')->willThrowException(new ForbiddenException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroy(9)->getStatus());
	}

	public function testReorder(): void {
		$this->service->expects($this->once())->method('reorder')
			->with('alice', 2, [3, 1, 2])->willReturn([['id' => 3]]);
		$this->assertSame([['id' => 3]], $this->controller->reorder(2, [3, 1, 2])->getData());
	}
}
