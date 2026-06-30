<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\RecordController;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ImportService;
use OCA\Dataforms\Service\RecordService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class RecordControllerTest extends TestCase {
	private RecordService $service;
	private ImportService $importService;
	private ISession $appSession;
	private RecordController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(RecordService::class);
		$this->importService = $this->createMock(ImportService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->appSession = $this->createMock(ISession::class);
		$this->controller = new RecordController($this->createMock(IRequest::class), $this->service, $this->importService, $session, $this->appSession);
	}

	public function testIndexDecodesFiltersAndReleasesSession(): void {
		$this->appSession->expects($this->atLeastOnce())->method('close');
		$this->service->expects($this->once())->method('list')
			->with('alice', 2, 50, 0, 'updated', 'DESC', '', [['field' => 'a', 'op' => 'eq', 'value' => 1]])
			->willReturn(['records' => [], 'total' => 0]);
		$res = $this->controller->index(2, 50, 0, 'updated', 'DESC', '', json_encode([['field' => 'a', 'op' => 'eq', 'value' => 1]]));
		$this->assertSame(['records' => [], 'total' => 0], $res->getData());
	}

	public function testIndexIgnoresInvalidFilterJson(): void {
		$this->service->expects($this->once())->method('list')
			->with('alice', 2, 50, 0, 'updated', 'DESC', '', [])->willReturn(['records' => []]);
		$this->controller->index(2, 50, 0, 'updated', 'DESC', '', 'not-json');
	}

	public function testOptionsLists(): void {
		$this->service->expects($this->once())->method('options')
			->with('alice', 2, 'name', 'q')->willReturn([['id' => 1, 'label' => 'x']]);
		$this->assertSame([['id' => 1, 'label' => 'x']], $this->controller->options(2, 'name', 'q')->getData());
	}

	public function testShowMapsNotFound(): void {
		$this->service->method('get')->willThrowException(new NotFoundException('Record not found'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->show(5)->getStatus());
	}

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, ['title' => 'x'])->willReturn(['id' => 9]);
		$res = $this->controller->create(2, ['title' => 'x']);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateValidationIncludesPerFieldErrors(): void {
		$this->service->method('create')->willThrowException(new ValidationException('Validation failed', ['title' => 'required']));
		$res = $this->controller->create(2, []);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'Validation failed', 'errors' => ['title' => 'required']], $res->getData());
	}

	public function testUpdateReturnsTheRecord(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 5, ['title' => 'y'])->willReturn(['id' => 5]);
		$this->assertSame(['id' => 5], $this->controller->update(5, ['title' => 'y'])->getData());
	}

	public function testImportReturnsTheReport(): void {
		$this->importService->expects($this->once())->method('importCsv')
			->with('alice', 2, 'a,b')->willReturn(['imported' => 1, 'failed' => 0, 'errors' => []]);
		$this->assertSame(['imported' => 1, 'failed' => 0, 'errors' => []], $this->controller->import(2, 'a,b')->getData());
	}

	public function testImportValidationMapsToBadRequestWithoutErrors(): void {
		$this->importService->method('importCsv')->willThrowException(new ValidationException('The CSV file is empty'));
		$res = $this->controller->import(2, '');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'The CSV file is empty'], $res->getData());
	}

	public function testDestroyReturnsEmptyOnSuccess(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 5);
		$this->assertSame([], $this->controller->destroy(5)->getData());
	}

	public function testDestroyBlockedByIntegrityRuleMapsTo422(): void {
		$this->service->method('delete')->willThrowException(new ValidationException('This record is referenced by other records and cannot be deleted'));
		$res = $this->controller->destroy(5);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$this->assertSame(['message' => 'This record is referenced by other records and cannot be deleted'], $res->getData());
	}

	public function testHistoryLists(): void {
		$this->service->expects($this->once())->method('history')
			->with('alice', 5)->willReturn([['action' => 'create']]);
		$this->assertSame([['action' => 'create']], $this->controller->history(5)->getData());
	}
}
