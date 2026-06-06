<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Emails the configured users (resolved to their Nextcloud email addresses).
 * Internal only — recipients are Nextcloud users, never arbitrary addresses.
 * action_config: { users: string[], subject?: string, body?: string }.
 */
class EmailAction implements IAction {

	public function __construct(
		private IMailer $mailer,
		private IUserManager $userManager,
		private LoggerInterface $logger,
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
			$email = $user?->getEMailAddress();
			if ($email) {
				$recipients[$email] = $user->getDisplayName();
			}
		}
		if ($recipients === []) {
			return;
		}

		$subject = trim((string)($context->config['subject'] ?? '')) ?: $context->automationName;
		$body = trim((string)($context->config['body'] ?? $context->config['message'] ?? ''));

		try {
			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo($recipients);
			$message->setPlainBody($body !== '' ? $body : $subject);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms email action failed', ['exception' => $e]);
		}
	}
}
