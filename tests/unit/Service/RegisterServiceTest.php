<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\FormMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The register service: visibility, the read/write/manage permission gates,
 * CRUD, favourites and soft-delete — all access decided server-side.
 */
class RegisterServiceTest extends TestCase {
	private RegisterMapper&MockObject $mapper;
	private ShareMapper&MockObject $shareMapper;
	private ViewMapper&MockObject $viewMapper;
	private FormMapper&MockObject $formMapper;
	private RecordMapper&MockObject $recordMapper;
	private IGroupManager&MockObject $groupManager;
	private IConfig&MockObject $config;
	private ITimeFactory&MockObject $time;
	private RegisterService $service;
	/** Stateful backing store for the user's favourites (config getUserValue/setUserValue). */
	private string $favStore = '[]';

	protected function setUp(): void {
		$this->mapper = $this->createMock(RegisterMapper::class);
		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->viewMapper = $this->createMock(ViewMapper::class);
		$this->formMapper = $this->createMock(FormMapper::class);
		$this->recordMapper = $this->createMock(RecordMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->time = $this->createMock(ITimeFactory::class);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(fn (string $uid) => $uid === 'ghost' ? null : $user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['team']);

		$this->favStore = '[]';
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $u, string $app, string $key, $default = '') => $key === 'favorites' ? $this->favStore : $default,
		);
		$this->config->method('setUserValue')->willReturnCallback(function (string $u, string $app, string $key, $value): void {
			if ($key === 'favorites') {
				$this->favStore = (string)$value;
			}
		});

		$this->service = new RegisterService(
			$this->mapper, $this->shareMapper, $this->viewMapper, $this->formMapper,
			$this->recordMapper, $this->groupManager, $userManager, $this->config, $this->time,
		);
	}

	private function register(int $id, string $owner): Register {
		$r = new Register();
		$r->setId($id);
		$r->setOwner($owner);
		$r->setTitle('Fines');
		return $r;
	}

	// ---- find / read gate -------------------------------------------------

	public function testFindReturnsAReadableRegisterForItsOwner(): void {
		$this->mapper->method('find')->with(5)->willReturn($this->register(5, 'alice'));
		$this->assertSame(5, $this->service->find('alice', 5)->getId());
	}

	public function testFindMapsMissingRegisterToNotFound(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(NotFoundException::class);
		$this->service->find('alice', 5);
	}

	public function testFindHidesARegisterTheUserCannotRead(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->method('permissionsFor')->willReturn(0);
		$this->expectException(NotFoundException::class);
		$this->service->find('bob', 5);
	}

	// ---- findAll ----------------------------------------------------------

	public function testFindAllDecoratesEveryVisibleRegister(): void {
		$this->mapper->method('findAllForUser')->with('alice', ['team'])->willReturn([
			$this->register(5, 'alice'), $this->register(6, 'alice'),
		]);
		$this->favStore = json_encode([6]);
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 3, 6 => 0]);
		$out = $this->service->findAll('alice');
		$this->assertCount(2, $out);
		$this->assertSame(3, $out[0]['recordCount']);
		$this->assertTrue($out[1]['favorite']);
		$this->assertTrue($out[0]['isOwner']);
		$this->assertTrue($out[0]['canManage']);
	}

	// ---- favourites -------------------------------------------------------

	public function testSetFavoriteAddsTheRegister(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 0]);
		$res = $this->service->setFavorite('alice', 5, true);
		$this->assertTrue($res['favorite']);
		$this->assertSame(json_encode([5]), $this->favStore);
	}

	public function testSetFavoriteRemovesTheRegister(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->favStore = json_encode([5, 9]);
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 0]);
		$res = $this->service->setFavorite('alice', 5, false);
		$this->assertFalse($res['favorite']);
		$this->assertSame(json_encode([9]), $this->favStore);
	}

	public function testFavoritesToleratesCorruptStoredValue(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->favStore = 'not json';
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 0]);
		$res = $this->service->setFavorite('alice', 5, true);
		$this->assertTrue($res['favorite']);
		$this->assertSame(json_encode([5]), $this->favStore);
	}

	// ---- create / update / delete -----------------------------------------

	public function testCreatePersistsAndDecorates(): void {
		$this->time->method('getTime')->willReturn(1000);
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Register $r) {
				$r->setId(7);
				return $r;
			});
		$this->recordMapper->method('countsByRegisterIds')->willReturn([7 => 0]);
		$res = $this->service->create('alice', 'Fines', 'desc', 'shield', '#fff');
		$this->assertSame('Fines', $res['title']);
		$this->assertTrue($res['isOwner']);
	}

	public function testUpdateAppliesOnlyProvidedChanges(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->time->method('getTime')->willReturn(2000);
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 0]);
		$captured = null;
		$this->mapper->method('update')->willReturnCallback(function (Register $r) use (&$captured) {
			$captured = $r;
			return $r;
		});
		$this->service->update('alice', 5, ['title' => 'New', 'description' => 'D', 'icon' => 'star', 'color' => '#000']);
		$this->assertSame('New', $captured->getTitle());
		$this->assertSame('star', $captured->getIcon());
		$this->assertSame(2000, $captured->getUpdated());
	}

	public function testUpdateRefusedWithoutManage(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ | Share::PERMISSION_WRITE);
		$this->expectException(ForbiddenException::class);
		$this->service->update('bob', 5, ['title' => 'New']);
	}

	public function testDeleteSoftDeletesAndCascadesShareViewForm(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->time->method('getTime')->willReturn(3000);
		$captured = null;
		$this->mapper->method('update')->willReturnCallback(function (Register $r) use (&$captured) {
			$captured = $r;
			return $r;
		});
		$this->shareMapper->expects($this->once())->method('deleteByRegister')->with(5);
		$this->viewMapper->expects($this->once())->method('deleteByRegister')->with(5);
		$this->formMapper->expects($this->once())->method('deleteByRegister')->with(5);
		$this->service->delete('alice', 5);
		$this->assertSame(3000, $captured->getDeletedAt());
	}

	// ---- write / manage gates ---------------------------------------------

	public function testFindWritableAllowsAWriteSharee(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ | Share::PERMISSION_WRITE);
		$this->assertSame(5, $this->service->findWritable('bob', 5)->getId());
	}

	public function testFindWritableRefusesAReadOnlySharee(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ);
		$this->expectException(ForbiddenException::class);
		$this->service->findWritable('bob', 5);
	}

	public function testFindManageableAllowsTheOwner(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->assertSame(5, $this->service->findManageable('alice', 5)->getId());
	}

	// ---- permission helpers -----------------------------------------------

	public function testPermissionsForOwnerIsFull(): void {
		$perms = $this->service->permissionsFor($this->register(5, 'alice'), 'alice');
		$this->assertSame(Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE, $perms);
	}

	public function testOwnerIsAlwaysManager(): void {
		$this->shareMapper->expects($this->never())->method('permissionsFor');
		$this->assertTrue($this->service->isManager($this->register(5, 'alice'), 'alice'));
	}

	public function testShareWithManageBitIsManager(): void {
		$this->shareMapper->method('permissionsFor')->with(5, 'bob', ['team'])
			->willReturn(Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE);
		$this->assertTrue($this->service->isManager($this->register(5, 'alice'), 'bob'));
	}

	public function testReadOnlyShareIsNotManager(): void {
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ);
		$this->assertFalse($this->service->isManager($this->register(5, 'alice'), 'bob'));
	}

	public function testFindDecoratedForASharee(): void {
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ | Share::PERMISSION_WRITE);
		$this->recordMapper->method('countsByRegisterIds')->willReturn([5 => 4]);
		$out = $this->service->findDecorated('bob', 5);
		$this->assertFalse($out['isOwner']);
		$this->assertTrue($out['canWrite']);
		$this->assertFalse($out['canManage']);
		$this->assertSame(4, $out['recordCount']);
	}

	public function testGroupIdsEmptyForUnknownUser(): void {
		// 'ghost' resolves to no user → no group shares consulted.
		$this->mapper->method('find')->willReturn($this->register(5, 'alice'));
		$this->shareMapper->expects($this->once())->method('permissionsFor')->with(5, 'ghost', [])->willReturn(0);
		$this->expectException(NotFoundException::class);
		$this->service->find('ghost', 5);
	}
}
