<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Listener;

use OCA\Dataforms\BackgroundJob\RunAutomationsJob;
use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Event\RecordDeletedEvent;
use OCA\Dataforms\Event\RecordUpdatedEvent;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * The workflow engine: on a record event, run the register's matching
 * automations. The condition is evaluated with the *existing* RuleEvaluator
 * (no new logic language); actions run through the ActionRegistry. Best-effort —
 * a failing automation never breaks the record write.
 *
 * Actions are split by cost. **Inline** actions (in-app notification, set-field)
 * are cheap, internal and loop-safe, so they run here synchronously. **Deferred**
 * actions (outbound webhook, email) have slow or external side effects, so the
 * listener only enqueues a {@see RunAutomationsJob}; they are delivered off the
 * request thread by the background-job queue. This keeps a slow or hung endpoint
 * from blocking the record write or exhausting the PHP worker pool.
 *
 * @template-implements IEventListener<Event>
 */
class AutomationListener implements IEventListener {

	public function __construct(
		private AutomationService $automations,
		private ActionRegistry $registry,
		private RuleEvaluator $evaluator,
		private IJobList $jobList,
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
		$hasDeferred = false;
		try {
			foreach ($this->automations->findActive($event->getRegisterId(), $trigger) as $automation) {
				$action = $this->registry->get($automation->getActionType());
				if ($action === null) {
					continue;
				}
				$conditionJson = $automation->getCondition();
				$condition = ($conditionJson !== null && $conditionJson !== '')
					? (json_decode($conditionJson, true) ?: null)
					: null;
				if (!$this->evaluator->matches($condition, $event->getValues())) {
					continue;
				}
				if ($action->isDeferred()) {
					// Run later, off the request thread (see RunAutomationsJob).
					$hasDeferred = true;
					continue;
				}
				$configJson = $automation->getActionConfig();
				$config = ($configJson !== null && $configJson !== '')
					? (json_decode($configJson, true) ?: [])
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

		if ($hasDeferred) {
			$this->jobList->add(RunAutomationsJob::class, [
				'registerId' => $event->getRegisterId(),
				'recordId' => $event->getRecordId(),
				'userId' => $event->getUserId(),
				'trigger' => $trigger,
				'values' => $event->getValues(),
			]);
		}
	}
}
