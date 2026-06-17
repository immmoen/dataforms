<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Listener;

use OCA\Dataforms\BackgroundJob\RunAutomationsJob;
use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Listener\AutomationListener;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCA\Dataforms\Workflow\IAction;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the workflow engine's routing in AutomationListener — the hot path for
 * every record change since 0.38.1:
 *   - an inline action runs synchronously and is logged 'ok';
 *   - a deferred action is NOT run inline but enqueues exactly one job;
 *   - one failing inline action is logged 'error' yet never stops the next;
 *   - a condition mismatch skips the action entirely (no run, no log);
 *   - an unregistered action type is skipped without crashing;
 *   - a non-record event is ignored before any automation is loaded.
 */
class AutomationListenerTest extends TestCase {
	/** @var AutomationService&MockObject */
	private $automations;
	/** @var ActionRegistry&MockObject */
	private $registry;
	/** @var RuleEvaluator&MockObject */
	private $evaluator;
	/** @var IJobList&MockObject */
	private $jobList;
	/** @var AutomationLogService&MockObject */
	private $log;

	protected function setUp(): void {
		$this->automations = $this->createMock(AutomationService::class);
		$this->registry = $this->createMock(ActionRegistry::class);
		$this->evaluator = $this->createMock(RuleEvaluator::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->log = $this->createMock(AutomationLogService::class);
	}

	private function listener(): AutomationListener {
		return new AutomationListener(
			$this->automations,
			$this->registry,
			$this->evaluator,
			$this->jobList,
			$this->log,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function automation(string $type, string $name = 'A'): Automation {
		$a = new Automation();
		$a->setActionType($type);
		$a->setName($name);
		$a->setTrigger('create');
		$a->setCondition(null);
		$a->setActionConfig(json_encode(['k' => 'v']));
		return $a;
	}

	private function event(): RecordCreatedEvent {
		return new RecordCreatedEvent(5, 9, 'bob', ['status' => 'open']);
	}

	public function testInlineActionRunsAndIsLogged(): void {
		$action = $this->createMock(IAction::class);
		$action->method('isDeferred')->willReturn(false);
		$action->expects($this->once())->method('run')
			->with($this->isInstanceOf(ActionContext::class));

		$this->automations->method('findActive')->willReturn([$this->automation('notify')]);
		$this->registry->method('get')->willReturn($action);
		$this->evaluator->method('matches')->willReturn(true);

		$this->log->expects($this->once())->method('record')
			->with($this->isInstanceOf(Automation::class), 5, 9, AutomationLogService::STATUS_OK);
		$this->jobList->expects($this->never())->method('add');

		$this->listener()->handle($this->event());
	}

	public function testDeferredActionEnqueuesOneJobAndDoesNotRunInline(): void {
		$action = $this->createMock(IAction::class);
		$action->method('isDeferred')->willReturn(true);
		$action->expects($this->never())->method('run');

		$this->automations->method('findActive')->willReturn([$this->automation('webhook')]);
		$this->registry->method('get')->willReturn($action);
		$this->evaluator->method('matches')->willReturn(true);

		// A deferred run is logged by the job, not the listener.
		$this->log->expects($this->never())->method('record');
		$this->jobList->expects($this->once())->method('add')->with(
			RunAutomationsJob::class,
			$this->callback(static function (array $args): bool {
				return $args['registerId'] === 5
					&& $args['recordId'] === 9
					&& $args['userId'] === 'bob'
					&& $args['trigger'] === 'create'
					&& $args['values'] === ['status' => 'open'];
			}),
		);

		$this->listener()->handle($this->event());
	}

	public function testOneFailingInlineActionDoesNotStopTheNext(): void {
		$boom = $this->createMock(IAction::class);
		$boom->method('isDeferred')->willReturn(false);
		$boom->method('run')->willThrowException(new \RuntimeException('boom'));

		$ok = $this->createMock(IAction::class);
		$ok->method('isDeferred')->willReturn(false);
		$ok->expects($this->once())->method('run');

		$this->automations->method('findActive')->willReturn([
			$this->automation('set_field', 'first'),
			$this->automation('notify', 'second'),
		]);
		$this->registry->method('get')->willReturnOnConsecutiveCalls($boom, $ok);
		$this->evaluator->method('matches')->willReturn(true);

		$statuses = [];
		$this->log->method('record')->willReturnCallback(
			static function (Automation $a, int $r, ?int $rec, string $status, ?string $msg = null) use (&$statuses): void {
				$statuses[] = $status;
			},
		);

		$this->listener()->handle($this->event());

		$this->assertSame(
			[AutomationLogService::STATUS_ERROR, AutomationLogService::STATUS_OK],
			$statuses,
			'the failing action is logged error, then the next still runs and is logged ok',
		);
	}

	public function testConditionMismatchSkipsAction(): void {
		$action = $this->createMock(IAction::class);
		$action->method('isDeferred')->willReturn(false);
		$action->expects($this->never())->method('run');

		$this->automations->method('findActive')->willReturn([$this->automation('notify')]);
		$this->registry->method('get')->willReturn($action);
		$this->evaluator->method('matches')->willReturn(false);

		$this->log->expects($this->never())->method('record');
		$this->jobList->expects($this->never())->method('add');

		$this->listener()->handle($this->event());
	}

	public function testUnregisteredActionTypeIsSkipped(): void {
		$this->automations->method('findActive')->willReturn([$this->automation('does_not_exist')]);
		$this->registry->method('get')->willReturn(null);

		$this->log->expects($this->never())->method('record');
		$this->jobList->expects($this->never())->method('add');

		// No exception even though the type is unknown.
		$this->listener()->handle($this->event());
	}

	public function testNonRecordEventIsIgnored(): void {
		$this->automations->expects($this->never())->method('findActive');
		$this->listener()->handle(new Event());
	}
}
