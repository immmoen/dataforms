<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Notification\IManager;

/**
 * Sends a Nextcloud notification to the configured recipients. Internal only —
 * no external surface. action_config: { users: string[], message?: string }.
 */
class NotifyAction implements IAction {

	public function __construct(
		private IManager $notificationManager,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	public function getType(): string {
		return 'notify';
	}

	public function run(ActionContext $context): void {
		$recipients = array_values(array_filter(array_map('strval', (array)($context->config['users'] ?? []))));
		// Fall back to the actor so a misconfigured automation still does something visible.
		if ($recipients === [] && $context->userId !== '') {
			$recipients = [$context->userId];
		}
		$message = trim((string)($context->config['message'] ?? ''));

		foreach ($recipients as $uid) {
			if (!$this->userManager->userExists($uid)) {
				continue;
			}
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('dataforms')
				->setUser($uid)
				->setDateTime($this->time->getDateTime())
				->setObject('record', (string)$context->recordId)
				->setSubject('record_event', [
					'automation' => $context->automationName,
					'registerId' => $context->registerId,
					'recordId' => $context->recordId,
					'message' => $message,
				]);
			$this->notificationManager->notify($notification);
		}
	}
}
