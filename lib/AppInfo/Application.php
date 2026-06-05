<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\AppInfo;

use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Event\RecordDeletedEvent;
use OCA\Dataforms\Event\RecordUpdatedEvent;
use OCA\Dataforms\Listener\AutomationListener;
use OCA\Dataforms\Listener\ReferenceListener;
use OCA\Dataforms\Notification\Notifier;
use OCA\Dataforms\Reference\FormReferenceProvider;
use OCA\Dataforms\Search\FormSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Collaboration\Reference\RenderReferenceEvent;

/**
 * Application bootstrap. Most services are auto-wired by the DI container; here
 * we register the cross-app integrations that have to be declared explicitly.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'dataforms';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Smart Picker / unified search: lets a form be searched and inserted
		// into a document, then rendered as a rich interactive card.
		$context->registerSearchProvider(FormSearchProvider::class);
		$context->registerReferenceProvider(FormReferenceProvider::class);
		$context->registerEventListener(RenderReferenceEvent::class, ReferenceListener::class);

		// Workflow: run automations when a record changes, and render the
		// notifications they raise.
		$context->registerEventListener(RecordCreatedEvent::class, AutomationListener::class);
		$context->registerEventListener(RecordUpdatedEvent::class, AutomationListener::class);
		$context->registerEventListener(RecordDeletedEvent::class, AutomationListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		// Nothing to boot eagerly yet.
	}
}
