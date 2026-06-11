<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Service\ServiceAccountService;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;

/**
 * The default + named-extra service accounts: storage round-trips, "configured"
 * gating, the admin/builder listings, removal, and the rule that an
 * admin-supplied id is never used as a raw credentials-store key.
 */
class ServiceAccountServiceTest extends TestCase {

	private function service(): ServiceAccountService {
		$cfgStore = [];
		$credStore = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v) use (&$cfgStore): bool {
			$cfgStore[$k] = $v;
			return true;
		});
		$appConfig->method('getValueString')->willReturnCallback(function (string $a, string $k, string $d = '') use (&$cfgStore): string {
			return $cfgStore[$k] ?? $d;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $a, string $k) use (&$cfgStore): bool {
			unset($cfgStore[$k]);
			return true;
		});

		$cred = $this->createMock(ICredentialsManager::class);
		$cred->method('store')->willReturnCallback(function (string $uid, string $id, $c) use (&$credStore): void {
			$credStore[$id] = $c;
		});
		$cred->method('retrieve')->willReturnCallback(function (string $uid, string $id) use (&$credStore) {
			return $credStore[$id] ?? null;
		});
		$cred->method('delete')->willReturnCallback(function (string $uid, string $id) use (&$credStore): int {
			$existed = isset($credStore[$id]);
			unset($credStore[$id]);
			return $existed ? 1 : 0;
		});

		return new ServiceAccountService($appConfig, $cred);
	}

	public function testDefaultAccountRoundTrip(): void {
		$s = $this->service();
		$this->assertFalse($s->isConfigured());
		$this->assertFalse($s->anyConfigured());

		$s->save('http://localhost/', 'admin', 'secret');
		$this->assertTrue($s->isConfigured());
		$this->assertTrue($s->anyConfigured());
		$this->assertSame('http://localhost', $s->getInternalUrl()); // trailing slash trimmed
		$this->assertSame(['username' => 'admin', 'password' => 'secret'], $s->getCredentials());
	}

	public function testDefaultBlankPasswordKeepsSecret(): void {
		$s = $this->service();
		$s->save('http://localhost', 'admin', 'secret');
		$s->save('http://localhost', 'admin2', ''); // change username, keep password
		$this->assertSame(['username' => 'admin2', 'password' => 'secret'], $s->getCredentials());
	}

	public function testExtraAccountRoundTrip(): void {
		$s = $this->service();
		$id = $s->saveExtra('', 'Team bot', 'http://localhost', 'bot', 'botpw');
		$this->assertStringStartsWith('sa_', $id);
		$this->assertTrue($s->isConfigured($id));
		$this->assertSame(['username' => 'bot', 'password' => 'botpw'], $s->getCredentials($id));

		// accountList offers the configured extra (default not set here).
		$names = array_column($s->accountList(), 'name');
		$this->assertContains('Team bot', $names);
		$this->assertNotContains('Default', $names);

		// adminList always includes the default row plus the extra, no passwords.
		$admin = $s->adminList();
		$this->assertCount(2, $admin);
		$this->assertTrue($admin[0]['isDefault']);
		$this->assertArrayNotHasKey('password', $admin[1]);
	}

	public function testExtraBlankPasswordKeepsSecret(): void {
		$s = $this->service();
		$id = $s->saveExtra('', 'Bot', 'http://localhost', 'bot', 'botpw');
		$s->saveExtra($id, 'Bot renamed', 'http://localhost', 'bot2', ''); // keep password
		$this->assertSame(['username' => 'bot2', 'password' => 'botpw'], $s->getCredentials($id));
	}

	public function testRemoveExtra(): void {
		$s = $this->service();
		$id = $s->saveExtra('', 'Bot', 'http://localhost', 'bot', 'botpw');
		$this->assertTrue($s->isConfigured($id));
		$s->remove($id);
		$this->assertFalse($s->isConfigured($id));
		$this->assertCount(1, $s->adminList()); // just the default row
	}

	public function testAdminSuppliedIdIsNeverUsedAsCredKey(): void {
		$s = $this->service();
		// A bogus id that isn't an existing account must NOT be honoured — a fresh
		// generated id is used instead (no arbitrary credentials-store key).
		$id = $s->saveExtra('../../evil', 'X', 'http://localhost', 'u', 'pw');
		$this->assertStringStartsWith('sa_', $id);
		$this->assertNotSame('../../evil', $id);
	}
}
