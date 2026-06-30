<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\FormController;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FormService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FormControllerTest extends TestCase {
	private FormService $service;
	private FormController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(FormService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->controller = new FormController($this->createMock(IRequest::class), $this->service, $session);
	}

	public function testIndexLists(): void {
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testIndexMapsNotFound(): void {
		$this->service->method('listForRegister')->willThrowException(new NotFoundException('Register not found'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->index(2)->getStatus());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, 'F', ['sections' => []])->willReturn(['id' => 9]);
		$res = $this->controller->create(2, 'F', ['sections' => []]);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('create')->willThrowException(new ValidationException('A form needs a title'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(2)->getStatus());
	}

	public function testUpdateBuildsChanges(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['title' => 'N', 'definition' => ['x' => 1]])->willReturn(['id' => 9]);
		$this->controller->update(9, 'N', ['x' => 1]);
	}

	public function testUpdateMapsForbidden(): void {
		$this->service->method('update')->willThrowException(new ForbiddenException('nope'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->update(9, 'N')->getStatus());
	}

	public function testDestroy(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 9);
		$this->assertSame([], $this->controller->destroy(9)->getData());
	}
}
