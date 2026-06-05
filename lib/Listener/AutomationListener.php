<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Listener;

use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Event\RecordDeletedEvent;
use OCA\Dataforms\Event\RecordUpdatedEvent;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * The workflow engine: on a record event, run the register's matching
 * automations. The condition is evaluated with the *existing* RuleEvaluator
 * (no new logic language); actions run through the ActionRegistry. Best-effort —
 * a failing automation never breaks the record write.
 *
 * @template-implements IEventListener<Event>
 */
class AutomationListener implements IEventListener {

	public function __construct(
		private AutomationService $automations,
		private ActionRegistry $registry,
		private RuleEvaluator $evaluator,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$trigger = match (true) {
			$event instanceof RecordCreatedEvent => 'create',
			$event instanceof RecordUpdatedEvent => 'update',
			$event instanceof RecordDeletedEvent => 'delete',
			default => null,
		};
		if ($trigger === null) {
			return;
		}
		/** @var RecordCreatedEvent|RecordUpdatedEvent|RecordDeletedEvent $event */
		try {
			foreach ($this->automations->findActive($event->getRegisterId(), $trigger) as $automation) {
				$condition = $automation->getCondition()
					? (json_decode($automation->getCondition(), true) ?: null)
					: null;
				if (!$this->evaluator->matches($condition, $event->getValues())) {
					continue;
				}
				$action = $this->registry->get($automation->getActionType());
				if ($action === null) {
					continue;
				}
				$config = $automation->getActionConfig()
					? (json_decode($automation->getActionConfig(), true) ?: [])
					: [];
				$action->run(new ActionContext(
					$event->getRegisterId(),
					$event->getRecordId(),
					$event->getUserId(),
					$automation->getName(),
					$event->getValues(),
					$config,
				));
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms automation failed', ['exception' => $e]);
		}
	}
}
