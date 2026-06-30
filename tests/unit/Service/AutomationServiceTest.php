<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Db\AutomationMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCA\Dataforms\Workflow\IAction;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AutomationService: the manage-gated CRUD plus the save-time validation of
 * action types, triggers and per-action config (the builder's guard rails), and
 * the create-only restriction on the cross-app actions (AUT-04/05/06/20/21).
 */
class AutomationServiceTest extends TestCase {
	private AutomationMapper&MockObject $mapper;
	private RegisterService&MockObject $registerService;
	private ActionRegistry&MockObject $registry;
	private WorkflowSettings&MockObject $settings;
	private ServiceAccountService&MockObject $account;
	private AutomationService $service;

	private const KNOWN = ['notify', 'webhook', 'provision_folders', 'apply_template', 'create_talk_room', 'create_deck_board', 'add_calendar_event'];

	protected function setUp(): void {
		$this->mapper = $this->createMock(AutomationMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->registry = $this->createMock(ActionRegistry::class);
		$this->settings = $this->createMock(WorkflowSettings::class);
		$this->account = $this->createMock(ServiceAccountService::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);

		$this->registry->method('get')->willReturnCallback(fn (string $t): ?IAction => in_array($t, self::KNOWN, true) ? $this->createMock(IAction::class) : null);
		$this->registry->method('types')->willReturn(self::KNOWN);
		$this->settings->method('isActionEnabled')->willReturn(true);
		$this->settings->method('maxFolders')->willReturn(50);
		$this->account->method('accountList')->willReturn([]);

		$this->service = new AutomationService($this->mapper, $this->registerService, $this->registry, $this->settings, $this->account, $time);
	}

	private function insertEcho(): void {
		$this->mapper->method('insert')->willReturnCallback(static function (Automation $a): Automation {
			$a->setId(7);
			return $a;
		});
	}

	private function expectInvalid(callable $fn, string $needle): void {
		try {
			$fn();
			$this->fail('expected ValidationException: ' . $needle);
		} catch (ValidationException $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
	}

	public function testAvailableActionTypesAndServiceAccountsDelegate(): void {
		$this->account->method('anyConfigured')->willReturn(true);
		$this->settings->method('availableActions')->with(self::KNOWN, true)->willReturn(['notify', 'webhook']);
		$this->assertSame(['notify', 'webhook'], $this->service->availableActionTypes());
		$this->account->method('accountList')->willReturn([['id' => 'default', 'name' => 'Default']]);
		$this->assertSame([], $this->service->serviceAccounts()); // accountList stubbed empty earlier wins
	}

	public function testListIsManageGated(): void {
		$this->registerService->expects($this->once())->method('findManageable')->with('alice', 5);
		$a = new Automation();
		$a->setActionType('notify');
		$this->mapper->method('findByRegister')->willReturn([$a]);
		$this->assertCount(1, $this->service->listForRegister('alice', 5));
	}

	public function testCreatePersistsAValidAutomation(): void {
		$this->insertEcho();
		$out = $this->service->create('alice', 5, [
			'name' => '  Notify team  ', 'trigger' => 'create', 'actionType' => 'notify',
			'actionConfig' => ['users' => ['bob']], 'condition' => null, 'enabled' => true,
		]);
		$this->assertSame(7, $out['id']);
		$this->assertSame('Notify team', $out['name']);
		$this->assertSame('create', $out['trigger']);
	}

	public function testCreateRejectsUnknownActionAndDisabledAction(): void {
		$this->expectInvalid(fn () => $this->service->create('alice', 5, ['trigger' => 'create', 'actionType' => 'bogus']), 'Unknown action');

		$settings = $this->createMock(WorkflowSettings::class);
		$settings->method('isActionEnabled')->willReturn(false);
		$svc = new AutomationService($this->mapper, $this->registerService, $this->registry, $settings, $this->account, $this->createMock(ITimeFactory::class));
		$this->expectInvalid(fn () => $svc->create('alice', 5, ['trigger' => 'create', 'actionType' => 'notify']), 'disabled by the administrator');
	}

	public function testCreateRejectsBadTriggerAndCreateOnlyViolation(): void {
		$this->expectInvalid(fn () => $this->service->create('alice', 5, ['trigger' => 'sometimes', 'actionType' => 'notify']), 'Unknown trigger');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, ['trigger' => 'update', 'actionType' => 'create_talk_room', 'actionConfig' => ['roomName' => 'X']]), 'only run when a record is created');
	}

	public function testValidatesEachActionConfig(): void {
		$mk = static fn (string $type, array $cfg, string $trigger = 'create') => ['trigger' => $trigger, 'actionType' => $type, 'actionConfig' => $cfg];
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('webhook', ['url' => 'ftp://x'])), 'http://');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('provision_folders', ['folders' => []])), 'at least one folder');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('provision_folders', ['folders' => ['a', 'b'], 'basePath' => 'x/<bad>'])), 'invalid path');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('apply_template', ['source' => 'a'])), 'both the template');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('create_talk_room', ['roomName' => ''])), 'needs a name');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('create_deck_board', ['title' => ''])), 'needs a title');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('add_calendar_event', ['title' => ''])), 'needs a title');
		$this->expectInvalid(fn () => $this->service->create('alice', 5, $mk('add_calendar_event', ['title' => 'X', 'startField' => ''])), 'date field');
	}

	public function testTooManyFoldersIsRejected(): void {
		$settings = $this->createMock(WorkflowSettings::class);
		$settings->method('isActionEnabled')->willReturn(true);
		$settings->method('maxFolders')->willReturn(2);
		$svc = new AutomationService($this->mapper, $this->registerService, $this->registry, $settings, $this->account, $this->createMock(ITimeFactory::class));
		$this->expectInvalid(fn () => $svc->create('alice', 5, ['trigger' => 'create', 'actionType' => 'provision_folders', 'actionConfig' => ['folders' => ['a', 'b', 'c']]]), 'Too many folders');
	}

	public function testStaleServiceAccountIsRejected(): void {
		$this->expectInvalid(
			fn () => $this->service->create('alice', 5, ['trigger' => 'create', 'actionType' => 'create_deck_board', 'actionConfig' => ['title' => 'B', 'serviceAccount' => 'gone']]),
			'service account no longer exists',
		);
	}

	public function testUpdateAppliesChangesAndReValidates(): void {
		$a = new Automation();
		$a->setId(7);
		$a->setRegisterId(5);
		$a->setName('Old');
		$a->setTrigger('create');
		$a->setActionType('notify');
		$a->setActionConfig(json_encode(['users' => ['x']]));
		$this->mapper->method('find')->willReturn($a);
		$this->mapper->method('update')->willReturnArgument(0);

		$out = $this->service->update('alice', 7, ['name' => 'New', 'trigger' => 'update', 'enabled' => false, 'condition' => ['logic' => 'and', 'rules' => []]]);
		$this->assertSame('New', $out['name']);
		$this->assertSame('update', $out['trigger']);
		$this->assertFalse($out['enabled']);
	}

	public function testDeleteAndNotFound(): void {
		$a = new Automation();
		$a->setRegisterId(5);
		$this->mapper->method('find')->willReturn($a);
		$this->mapper->expects($this->once())->method('delete')->with($a);
		$this->service->delete('alice', 7);

		$this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$svc = new AutomationService($this->mapper, $this->registerService, $this->registry, $this->settings, $this->account, $this->createMock(ITimeFactory::class));
		$this->expectException(NotFoundException::class);
		$svc->delete('alice', 999);
	}

	public function testFindActiveDelegates(): void {
		$this->mapper->method('findActive')->with(5, 'create')->willReturn([new Automation()]);
		$this->assertCount(1, $this->service->findActive(5, 'create'));
	}
}
