<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\ViewController;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ViewControllerTest extends TestCase {
	private ViewService $service;
	private ViewController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ViewService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new ViewController($this->createMock(IRequest::class), $this->service, $session);
	}

	public function testIndexLists(): void {
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, 'V', ['c' => 1], true)->willReturn(['id' => 9]);
		$res = $this->controller->create(2, 'V', ['c' => 1], true);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(['id' => 9], $res->getData());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('create')->willThrowException(new ValidationException('A view needs a title'));
		$res = $this->controller->create(2);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'A view needs a title'], $res->getData());
	}

	public function testUpdateBuildsChanges(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['title' => 'N', 'definition' => ['x' => 1], 'shared' => false])->willReturn(['id' => 9]);
		$this->controller->update(9, 'N', ['x' => 1], false);
	}

	public function testUpdateMapsForbidden(): void {
		$this->service->method('update')->willThrowException(new ForbiddenException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->update(9, 'N')->getStatus());
	}

	public function testDestroy(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 9);
		$res = $this->controller->destroy(9);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([], $res->getData());
	}
}
