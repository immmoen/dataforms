<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\EmailAction;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;

/**
 * EmailAction (AUT-08, unit): emails Nextcloud users resolved to their addresses
 * — never arbitrary addresses; a user without an email is skipped; nothing is
 * sent when no recipient resolves. Deferred action.
 */
class EmailActionTest extends TestCase {
	private function user(string $uid, ?string $email, string $display = 'X'): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('getDisplayName')->willReturn($display);
		return $u;
	}

	public function testSendsToResolvedAddressesOnly(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([
			['bob', $this->user('bob', 'bob@example.org', 'Bob')],
			['carol', $this->user('carol', null)], // no email → skipped
			['ghost', null],                         // unknown → skipped
		]);

		$message = $this->createMock(IMessage::class);
		$captured = [];
		$message->method('setTo')->willReturnCallback(function (array $to) use (&$captured, $message) {
			$captured = $to;
			return $message;
		});
		$message->method('setSubject')->willReturn($message);
		$message->method('setPlainBody')->willReturn($message);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		$action = new EmailAction($mailer, $users);
		$action->run(new ActionContext(5, 9, 'alice', 'Mail', [], ['users' => ['bob', 'carol', 'ghost'], 'subject' => 'Hi', 'body' => 'Body']));
		$this->assertSame(['bob@example.org' => 'Bob'], $captured);
	}

	public function testSendsNothingWhenNoRecipientResolves(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$action = new EmailAction($mailer, $users);
		$action->run(new ActionContext(5, 9, 'alice', 'Mail', [], ['users' => ['ghost']]));
	}

	public function testTypeAndDeferred(): void {
		$a = new EmailAction($this->createMock(IMailer::class), $this->createMock(IUserManager::class));
		$this->assertSame('email', $a->getType());
		$this->assertTrue($a->isDeferred());
	}
}
