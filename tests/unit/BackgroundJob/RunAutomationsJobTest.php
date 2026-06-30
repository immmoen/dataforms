<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\BackgroundJob;

use OCA\Dataforms\BackgroundJob\RunAutomationsJob;
use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\AutomationService;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCA\Dataforms\Workflow\IAction;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * RunAutomationsJob: off-thread runner for the DEFERRED actions (webhook, email)
 * of one record event. Re-checks the register's currently-enabled automations
 * against the captured snapshot, runs only the deferred ones whose condition
 * holds, and records each run (OK / error) — a failure never stops the others
 * (AUT-19/23).
 */
class RunAutomationsJobTest extends TestCase {
	/** @var list<array{status:string,error:?string}> */
	private array $logged = [];

	private function automation(string $type, ?array $condition = null): Automation {
		$a = new Automation();
		$a->setName('Auto-' . $type);
		$a->setActionType($type);
		$a->setActionConfig(json_encode(['url' => 'https://x']));
		$a->setCondition($condition === null ? null : json_encode($condition));
		return $a;
	}

	private function action(bool $deferred, bool $throws = false): IAction {
		$a = $this->createMock(IAction::class);
		$a->method('isDeferred')->willReturn($deferred);
		$a->method('run')->willReturnCallback(static function (ActionContext $c) use ($throws): void {
			if ($throws) {
				throw new \RuntimeException('endpoint down');
			}
		});
		return $a;
	}

	private function job(array $automations, array $actionsByType): RunAutomationsJob {
		$svc = $this->createMock(AutomationService::class);
		$svc->method('findActive')->willReturn($automations);
		$registry = $this->createMock(ActionRegistry::class);
		$registry->method('get')->willReturnCallback(static fn (string $t): ?IAction => $actionsByType[$t] ?? null);
		$log = $this->createMock(AutomationLogService::class);
		$log->method('record')->willReturnCallback(function (Automation $a, int $r, int $rec, string $status, ?string $err = null): void {
			$this->logged[] = ['status' => $status, 'error' => $err];
		});
		return new RunAutomationsJob(
			$this->createMock(ITimeFactory::class),
			$svc, $registry, new RuleEvaluator(new ExpressionEvaluator()), $log, $this->createMock(LoggerInterface::class),
		);
	}

	private function invokeJob(RunAutomationsJob $job, $argument): void {
		$m = new ReflectionMethod(RunAutomationsJob::class, 'run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function arg(array $values = []): array {
		return ['registerId' => 5, 'recordId' => 9, 'userId' => 'alice', 'trigger' => 'create', 'values' => $values];
	}

	public function testRunsDeferredActionsAndRecordsOk(): void {
		$job = $this->job([$this->automation('webhook')], ['webhook' => $this->action(true)]);
		$this->invokeJob($job, $this->arg());
		$this->assertSame([['status' => AutomationLogService::STATUS_OK, 'error' => null]], $this->logged);
	}

	public function testSkipsInlineActions(): void {
		$job = $this->job([$this->automation('notify')], ['notify' => $this->action(false)]);
		$this->invokeJob($job, $this->arg());
		$this->assertSame([], $this->logged, 'inline actions already ran in the listener');
	}

	public function testHonoursTheCondition(): void {
		$cond = ['logic' => 'and', 'rules' => [['field' => 'status', 'op' => 'eq', 'value' => 'open']]];
		$job = $this->job([$this->automation('webhook', $cond)], ['webhook' => $this->action(true)]);
		$this->invokeJob($job, $this->arg(['status' => 'closed'])); // condition not met → skip
		$this->assertSame([], $this->logged);
	}

	public function testRecordsAFailedRunButContinues(): void {
		$job = $this->job(
			[$this->automation('webhook'), $this->automation('webhook')],
			['webhook' => $this->action(true, true)],
		);
		$this->invokeJob($job, $this->arg());
		$this->assertCount(2, $this->logged); // both attempted
		$this->assertSame(AutomationLogService::STATUS_ERROR, $this->logged[0]['status']);
		$this->assertStringContainsString('endpoint down', (string)$this->logged[0]['error']);
	}

	public function testIgnoresAMalformedArgument(): void {
		$job = $this->job([], []);
		$this->invokeJob($job, 'not-an-array');
		$this->invokeJob($job, ['registerId' => 0, 'trigger' => 'create']);
		$this->assertSame([], $this->logged);
	}
}
