<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Listener;

use OCA\Dataforms\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the reference-widget script in any context that renders references
 * (Text, Talk, Collectives, …) so inserted form links draw the rich card.
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class ReferenceListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof RenderReferenceEvent)) {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
	}
}
