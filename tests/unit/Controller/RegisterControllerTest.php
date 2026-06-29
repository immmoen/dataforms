<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\RegisterController;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Controller seam: thin HTTP mapping over RegisterService. Confirms each action
 * returns the right status/body on success and delegates domain exceptions to
 * the shared mapper.
 */
class RegisterControllerTest extends TestCase {
	private RegisterService $service;
	private RegisterController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(RegisterService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new RegisterController($this->createMock(IRequest::class), $this->service, $session);
	}

	public function testIndexReturnsAllRegisters(): void {
		$this->service->method('findAll')->with('alice')->willReturn([['id' => 1]]);
		$res = $this->controller->index();
		$this->assertSame([['id' => 1]], $res->getData());
	}

	public function testShowReturnsTheRegister(): void {
		$this->service->method('findDecorated')->with('alice', 3)->willReturn(['id' => 3]);
		$res = $this->controller->show(3);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['id' => 3], $res->getData());
	}

	public function testShowMapsNotFound(): void {
		$this->service->method('findDecorated')->willThrowException(new NotFoundException('Register not found'));
		$res = $this->controller->show(3);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
	}

	public function testCreateRejectsAnEmptyTitle(): void {
		$res = $this->controller->create('   ');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'Title is required'], $res->getData());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->method('create')->with('alice', 'Fines', 'd', 'i', 'c')->willReturn(['id' => 5]);
		$res = $this->controller->create('Fines', 'd', 'i', 'c');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$this->assertSame(['id' => 5], $res->getData());
	}

	public function testUpdateBuildsChangesAndReturnsTheRegister(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 5, ['title' => 'New', 'color' => '#fff'])
			->willReturn(['id' => 5, 'title' => 'New']);
		$res = $this->controller->update(5, 'New', null, null, '#fff');
		$this->assertSame(['id' => 5, 'title' => 'New'], $res->getData());
	}

	public function testUpdateRejectsAnEmptyTitle(): void {
		$res = $this->controller->update(5, '  ');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'Title cannot be empty'], $res->getData());
	}

	public function testUpdateMapsForbidden(): void {
		$this->service->method('update')->willThrowException(new ForbiddenException('nope'));
		$res = $this->controller->update(5, 'New');
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testFavoriteReturnsTheRegister(): void {
		$this->service->method('setFavorite')->with('alice', 5, true)->willReturn(['id' => 5, 'favorite' => true]);
		$res = $this->controller->favorite(5, true);
		$this->assertSame(['id' => 5, 'favorite' => true], $res->getData());
	}

	public function testDestroyReturnsOkWithEmptyBody(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 5);
		$res = $this->controller->destroy(5);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame([], $res->getData());
	}
}
