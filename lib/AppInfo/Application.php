<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Application bootstrap.
 *
 * Phase 0: registers the app and its single page route. Services, event
 * listeners and capabilities are wired here via the DI container as later
 * phases land (RegisterService, FieldService, RuleEvaluatorService, ...).
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'dataforms';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Service registrations and event listeners are added in later phases.
		// Example (Phase 1+):
		// $context->registerService(RegisterService::class, ...);
		// $context->registerEventListener(RecordCreatedEvent::class, NotifyListener::class);
	}

	public function boot(IBootContext $context): void {
		// Nothing to boot eagerly yet.
	}
}
