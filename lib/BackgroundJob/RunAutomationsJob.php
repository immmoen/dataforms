<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\BackgroundJob;

use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the *deferred* workflow actions (outbound webhook, email) for a single
 * record event, off the request thread.
 *
 * The listener enqueues one of these jobs when a matching automation uses a
 * deferred action, so a slow or hung endpoint can never block the record write
 * or exhaust the PHP-FPM worker pool. Inline actions (in-app notification,
 * set-field) already ran synchronously in the listener; this job re-checks the
 * register's currently-enabled automations against the captured value snapshot
 * and runs only the deferred ones — picking up any enable/disable change made
 * between the write and the job firing.
 *
 * Argument: { registerId:int, recordId:int, userId:string, trigger:string,
 *             values:array<string,mixed> }.
 */
class RunAutomationsJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private AutomationService $automations,
		private ActionRegistry $registry,
		private RuleEvaluator $evaluator,
		private AutomationLogService $log,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$registerId = (int)($argument['registerId'] ?? 0);
		$recordId = (int)($argument['recordId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		$trigger = (string)($argument['trigger'] ?? '');
		$values = is_array($argument['values'] ?? null) ? $argument['values'] : [];
		if ($registerId <= 0 || $trigger === '') {
			return;
		}

		foreach ($this->automations->findActive($registerId, $trigger) as $automation) {
			try {
				$action = $this->registry->get($automation->getActionType());
				if ($action === null || !$action->isDeferred()) {
					continue; // inline actions already ran in the listener
				}
				$conditionJson = $automation->getCondition();
				$condition = ($conditionJson !== null && $conditionJson !== '')
					? (json_decode($conditionJson, true) ?: null)
					: null;
				if (!$this->evaluator->matches($condition, $values)) {
					continue;
				}
				$configJson = $automation->getActionConfig();
				$config = ($configJson !== null && $configJson !== '')
					? (json_decode($configJson, true) ?: [])
					: [];
				$action->run(new ActionContext(
					$registerId,
					$recordId,
					$userId,
					$automation->getName(),
					$values,
					$config,
				));
				$this->log->record($automation, $registerId, $recordId, AutomationLogService::STATUS_OK);
			} catch (\Throwable $e) {
				// Best-effort: one bad automation never stops the others.
				$this->log->record($automation, $registerId, $recordId, AutomationLogService::STATUS_ERROR, $e->getMessage());
				$this->logger->warning('Dataforms deferred automation failed', ['exception' => $e]);
			}
		}
	}
}
