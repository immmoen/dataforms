<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCP\IUserManager;
use OCP\Mail\IMailer;

/**
 * Emails the configured users (resolved to their Nextcloud email addresses).
 * Internal only — recipients are Nextcloud users, never arbitrary addresses.
 * action_config: { users: string[], subject?: string, body?: string }.
 */
class EmailAction implements IAction {

	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
	) {
	}

	public function getType(): string {
		return 'email';
	}

	public function isDeferred(): bool {
		return true; // SMTP can block; run off the request thread
	}

	public function run(ActionContext $context): void {
		$recipients = [];
		foreach ((array)($context->config['users'] ?? []) as $uid) {
			$user = $this->userManager->get((string)$uid);
			if ($user === null) {
				continue;
			}
			$email = $user->getEMailAddress();
			if ($email !== null && $email !== '') {
				$recipients[$email] = $user->getDisplayName();
			}
		}
		if ($recipients === []) {
			return;
		}

		$subject = trim((string)($context->config['subject'] ?? '')) ?: $context->automationName;
		$body = trim((string)($context->config['body'] ?? $context->config['message'] ?? ''));

		// A send failure throws; the engine records the run as failed and continues.
		$message = $this->mailer->createMessage();
		$message->setSubject($subject);
		$message->setTo($recipients);
		$message->setPlainBody($body !== '' ? $body : $subject);
		$this->mailer->send($message);
	}
}
