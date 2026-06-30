<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\ShareController;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ShareControllerTest extends TestCase {
	private ShareService $service;
	private ShareController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ShareService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new ShareController($this->createMock(IRequest::class), $this->service, $session);
	}

	public function testIndexLists(): void {
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testIndexMapsForbidden(): void {
		$this->service->method('listForRegister')->willThrowException(new ForbiddenException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->index(2)->getStatus());
	}

	public function testShareesSearches(): void {
		$this->service->expects($this->once())->method('searchSharees')
			->with('alice', 2, 'bob')->willReturn([['id' => 'bob']]);
		$this->assertSame([['id' => 'bob']], $this->controller->sharees(2, 'bob')->getData());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())->method('add')
			->with('alice', 2, 'user', 'bob', 3)->willReturn(new Share());
		$res = $this->controller->create(2, 'user', 'bob', 3);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('add')->willThrowException(new ValidationException('Unknown user: bob'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(2, 'user', 'bob', 1)->getStatus());
	}

	public function testUpdateSetsPermissions(): void {
		$share = new Share();
		$this->service->expects($this->once())->method('setPermissions')
			->with('alice', 9, 7)->willReturn($share);
		$this->assertSame($share, $this->controller->update(9, 7)->getData());
	}

	public function testDestroyMapsNotFound(): void {
		$this->service->method('remove')->willThrowException(new NotFoundException('Share not found'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroy(9)->getStatus());
	}

	public function testDestroyReturnsEmpty(): void {
		$this->service->expects($this->once())->method('remove')->with('alice', 9);
		$this->assertSame([], $this->controller->destroy(9)->getData());
	}
}
