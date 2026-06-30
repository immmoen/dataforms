<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Service\RegisterService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The shared manage-capability check used by services to let a register
 * manager act on content they do not own.
 */
class RegisterServiceTest extends TestCase {
	private ShareMapper $shareMapper;
	private RegisterService $service;

	protected function setUp(): void {
		$this->shareMapper = $this->createMock(ShareMapper::class);
		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['team']);
		$this->service = new RegisterService(
			$this->createMock(RegisterMapper::class),
			$this->shareMapper,
			$this->createMock(\OCA\Dataforms\Db\ViewMapper::class),
			$this->createMock(\OCA\Dataforms\Db\FormMapper::class),
			$this->createMock(\OCA\Dataforms\Db\RecordMapper::class),
			$groupManager,
			$userManager,
			$this->createMock(IConfig::class),
			$this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class),
		);
	}

	private function register(string $owner): Register {
		$r = new Register();
		$r->setId(5);
		$r->setOwner($owner);
		return $r;
	}

	public function testOwnerIsAlwaysManager(): void {
		$this->shareMapper->expects($this->never())->method('permissionsFor');
		$this->assertTrue($this->service->isManager($this->register('alice'), 'alice'));
	}

	public function testShareWithManageBitIsManager(): void {
		$this->shareMapper->method('permissionsFor')
			->with(5, 'bob', ['team'])
			->willReturn(Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE);
		$this->assertTrue($this->service->isManager($this->register('alice'), 'bob'));
	}

	public function testReadOnlyShareIsNotManager(): void {
		$this->shareMapper->method('permissionsFor')->willReturn(Share::PERMISSION_READ);
		$this->assertFalse($this->service->isManager($this->register('alice'), 'bob'));
	}
}
