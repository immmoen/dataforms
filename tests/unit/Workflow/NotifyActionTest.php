<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\NotifyAction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * NotifyAction (AUT-07/22): sends an in-app notification to existing recipients
 * only (an unknown uid is never notified — recipient access still applies), and
 * falls back to the actor when no recipients are configured. Inline action.
 */
class NotifyActionTest extends TestCase {
	private function notification(): INotification {
		$n = $this->createMock(INotification::class);
		// Fluent setters return $this.
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
			$n->method($m)->willReturn($n);
		}
		return $n;
	}

	private function action(IManager $mgr, IUserManager $users): NotifyAction {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());
		return new NotifyAction($mgr, $users, $time);
	}

	private function context(array $config): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Notify', ['title' => 'x'], $config);
	}

	public function testNotifiesOnlyExistingRecipients(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $u) => $u === 'bob');
		$mgr = $this->createMock(IManager::class);
		$mgr->method('createNotification')->willReturnCallback(fn () => $this->notification());
		$mgr->expects($this->once())->method('notify'); // only bob; ghost skipped

		$this->action($mgr, $users)->run($this->context(['users' => ['bob', 'ghost'], 'message' => 'hi']));
	}

	public function testFallsBackToTheActorWhenNoRecipientsConfigured(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$mgr = $this->createMock(IManager::class);
		$mgr->method('createNotification')->willReturnCallback(fn () => $this->notification());
		$mgr->expects($this->once())->method('notify'); // the actor alice
		$this->action($mgr, $users)->run($this->context(['users' => []]));
	}

	public function testIsInlineAndDeclaresItsType(): void {
		$a = $this->action($this->createMock(IManager::class), $this->createMock(IUserManager::class));
		$this->assertSame('notify', $a->getType());
		$this->assertFalse($a->isDeferred());
	}
}
