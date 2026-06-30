<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ShareService at the service seam: the manage-gate on every operation, the
 * sharee typeahead (users + groups, de-duplicated, owner excluded), share
 * validation, and the permission normalisation (read is implied; bits clamped).
 * The register permission gate itself (the OR-union) is RegisterService's, fully
 * covered there.
 */
class ShareServiceTest extends TestCase {
	private ShareMapper&MockObject $mapper;
	private RegisterService&MockObject $registerService;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private ShareService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ShareMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$this->service = new ShareService($this->mapper, $this->registerService, $this->userManager, $this->groupManager, $time);
	}

	private function register(string $owner = 'alice', int $id = 5): Register {
		$r = new Register();
		$r->setId($id);
		$r->setOwner($owner);
		return $r;
	}

	private function user(string $uid, string $display = ''): IUser&MockObject {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getDisplayName')->willReturn($display);
		return $u;
	}

	private function group(string $gid, string $display = ''): IGroup&MockObject {
		$g = $this->createMock(IGroup::class);
		$g->method('getGID')->willReturn($gid);
		$g->method('getDisplayName')->willReturn($display);
		return $g;
	}

	public function testListPrependsOwnerEntryBehindTheManageGate(): void {
		$this->registerService->expects($this->once())->method('findManageable')->with('alice', 5)->willReturn($this->register('alice'));
		$share = new Share();
		$share->setId(2);
		$share->setRegisterId(5);
		$share->setShareWith('bob');
		$share->setPermissions(Share::PERMISSION_READ);
		$this->mapper->method('findByRegister')->with(5)->willReturn([$share]);

		$out = $this->service->listForRegister('alice', 5);
		$this->assertTrue($out[0]['isOwner']);
		$this->assertSame('alice', $out[0]['shareWith']);
		$this->assertSame('bob', $out[1]['shareWith']);
	}

	public function testSearchShareesMergesUsersAndGroupsExcludingTheOwner(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$this->userManager->method('search')->willReturn([$this->user('bob', 'Bob'), $this->user('alice', 'Alice')]);
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('bob', 'Bob')]); // dup, de-duped by uid
		$this->groupManager->method('search')->willReturn([$this->group('staff', 'Staff')]);

		$out = $this->service->searchSharees('alice', 5, 'b');
		$ids = array_map(static fn ($r) => $r['id'] . ':' . $r['type'], $out);
		$this->assertContains('bob:user', $ids);
		$this->assertContains('staff:group', $ids);
		$this->assertNotContains('alice:user', $ids, 'the owner is never offered as a sharee');
	}

	public function testSearchShareesCapsTheUserResults(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$many = [];
		for ($i = 0; $i < 20; $i++) {
			$many[] = $this->user('u' . $i); // empty display name → falls back to uid
		}
		$this->userManager->method('search')->willReturn($many);
		$this->userManager->method('searchDisplayName')->willReturn([]);
		$this->groupManager->method('search')->willReturn([]);

		$out = $this->service->searchSharees('alice', 5, 'u');
		$this->assertCount(15, $out); // capped at 15 users
		$this->assertSame('u0', $out[0]['label']); // label falls back to the uid
	}

	public function testSearchShareesReturnsEmptyForBlankQuery(): void {
		$this->registerService->method('findManageable')->willReturn($this->register());
		$this->assertSame([], $this->service->searchSharees('alice', 5, '   '));
	}

	public function testAddValidatesTheSharee(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$this->userManager->method('userExists')->willReturnCallback(static fn (string $u) => in_array($u, ['bob', 'alice'], true));
		$this->groupManager->method('groupExists')->willReturn(false);

		$this->assertThrows(fn () => $this->service->add('alice', 5, 'user', '  ', 1), 'Pick a user or group');
		$this->assertThrows(fn () => $this->service->add('alice', 5, 'user', 'ghost', 1), 'Unknown user');
		$this->assertThrows(fn () => $this->service->add('alice', 5, 'group', 'ghosts', 1), 'Unknown group');
		$this->assertThrows(fn () => $this->service->add('alice', 5, 'user', 'alice', 1), 'owner already has');
	}

	public function testAddInsertsANewShareWithReadAlwaysImplied(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->mapper->method('findExisting')->willReturn(null);
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (Share $s) use (&$captured): Share {
			$captured = $s;
			$s->setId(7);
			return $s;
		});

		// Grant WRITE only → READ must be added; MANAGE bit (and junk) clamped off.
		$this->service->add('alice', 5, 'user', 'bob', Share::PERMISSION_WRITE | 64);
		$this->assertSame(Share::PERMISSION_READ | Share::PERMISSION_WRITE, $captured->getPermissions());
		$this->assertSame(Share::TYPE_USER, $captured->getShareType());
	}

	public function testAddUpdatesAnExistingShareInsteadOfDuplicating(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$this->groupManager->method('groupExists')->willReturn(true);
		$existing = new Share();
		$existing->setId(3);
		$existing->setShareType(Share::TYPE_GROUP);
		$this->mapper->method('findExisting')->with(5, Share::TYPE_GROUP, 'staff')->willReturn($existing);
		$this->mapper->expects($this->once())->method('update')->with($existing)->willReturnArgument(0);
		$this->mapper->expects($this->never())->method('insert');

		$out = $this->service->add('alice', 5, 'group', 'staff', Share::PERMISSION_MANAGE);
		$this->assertSame(Share::PERMISSION_READ | Share::PERMISSION_MANAGE, $out->getPermissions());
	}

	public function testZeroPermissionsFallBackToRead(): void {
		$this->registerService->method('findManageable')->willReturn($this->register('alice'));
		$this->userManager->method('userExists')->willReturn(true);
		$this->mapper->method('findExisting')->willReturn(null);
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (Share $s) use (&$captured): Share {
			$captured = $s;
			return $s;
		});
		$this->service->add('alice', 5, 'user', 'bob', 0);
		$this->assertSame(Share::PERMISSION_READ, $captured->getPermissions());
	}

	public function testSetPermissionsAndRemoveAreManageGated(): void {
		$share = new Share();
		$share->setId(9);
		$share->setRegisterId(5);
		$this->mapper->method('find')->willReturn($share);
		$this->registerService->expects($this->exactly(2))->method('findManageable')->with('alice', 5);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service->setPermissions('alice', 9, Share::PERMISSION_WRITE);
		$this->assertSame(Share::PERMISSION_READ | Share::PERMISSION_WRITE, $updated->getPermissions());

		$this->mapper->expects($this->once())->method('delete')->with($share);
		$this->service->remove('alice', 9);
	}

	public function testMissingShareMapsToNotFound(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->expectException(NotFoundException::class);
		$this->service->remove('alice', 999);
	}

	public function testManageGateRefusalPropagates(): void {
		$this->registerService->method('findManageable')->willThrowException(new ForbiddenException('no manage'));
		$this->expectException(ForbiddenException::class);
		$this->service->listForRegister('bob', 5);
	}

	private function assertThrows(callable $fn, string $needle): void {
		try {
			$fn();
			$this->fail('expected ValidationException containing "' . $needle . '"');
		} catch (ValidationException $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
	}
}
