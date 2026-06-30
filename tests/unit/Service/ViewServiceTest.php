<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\View;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
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

	public function testListReadGatesAndFlagsOwnership(): void {
		$this->registerService->expects($this->once())->method('find')->with('alice', 5);
		$this->mapper->method('findForRegister')->with(5, 'alice')->willReturn([$this->view('alice'), $this->view('bob')]);
		$out = $this->service->listForRegister('alice', 5);
		$this->assertTrue($out[0]['isOwner']);
		$this->assertFalse($out[1]['isOwner']);
	}

	public function testCreateRejectsEmptyTitle(): void {
		$this->registerService->method('find');
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, '   ', [], false);
	}

	public function testCreateNormalisesTheDefinitionAndDecorates(): void {
		$this->registerService->expects($this->once())->method('find')->with('alice', 5);
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (View $v) use (&$captured): View {
			$captured = $v;
			$v->setId(9);
			return $v;
		});

		$out = $this->service->create('alice', 5, '  My view  ', [
			'columns' => ['a', 'b'],
			'filters' => [['field' => 'a', 'op' => 'eq', 'value' => 'x']],
			'sort' => 'created',
			'direction' => 'asc',
			'search' => 'q',
		], true);

		$this->assertSame('My view', $captured->getTitle()); // trimmed
		$this->assertTrue($captured->getShared());
		$def = json_decode((string)$captured->getDefinition(), true);
		$this->assertSame(['a', 'b'], $def['columns']);
		$this->assertSame('created', $def['sort']);
		$this->assertSame('ASC', $def['direction']); // normalised to upper-case
		$this->assertTrue($out['isOwner']);
		$this->assertSame(9, $out['id']);
	}

	public function testCreateDefaultsAnUnknownDirectionToDesc(): void {
		$this->registerService->method('find');
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (View $v) use (&$captured): View {
			$captured = $v;
			return $v;
		});
		$this->service->create('alice', 5, 'V', ['direction' => 'sideways'], false);
		$this->assertSame('DESC', json_decode((string)$captured->getDefinition(), true)['direction']);
	}

	public function testUpdateAppliesProvidedChangesOnly(): void {
		$view = $this->view('alice');
		$view->setTitle('old');
		$this->mapper->method('find')->willReturn($view);
		$this->mapper->method('update')->willReturnArgument(0);

		$out = $this->service->update('alice', 3, [
			'title' => '  New  ',
			'shared' => true,
			'definition' => ['columns' => ['c'], 'sort' => 'updated'],
		]);
		$this->assertSame('New', $view->getTitle());
		$this->assertTrue($view->getShared());
		$this->assertSame(['c'], $out['definition']['columns']);
	}

	public function testUpdateIgnoresBlankTitleAndNonArrayDefinition(): void {
		$view = $this->view('alice');
		$view->setTitle('keep');
		$this->mapper->method('find')->willReturn($view);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->service->update('alice', 3, ['title' => '   ', 'definition' => 'not-an-array']);
		$this->assertSame('keep', $view->getTitle());
	}

	public function testMissingViewMapsToNotFound(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->expectException(NotFoundException::class);
		$this->service->update('alice', 999, ['title' => 'x']);
	}
}
