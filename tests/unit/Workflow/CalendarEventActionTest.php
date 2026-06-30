<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\CalendarEventAction;
use OCA\Dataforms\Workflow\RelationResolver;
use OCA\Dataforms\Workflow\ValueInterpolator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CalendarEventAction (AUT-12): the guard branches before the ICS write —
 * missing title/startField/start value, a missing/disabled owner, and a named
 * calendar that isn't found (no silent fallback). The actual event write goes
 * through Sabre's ICS builder and is asserted with a REAL side effect in the
 * cross-app E2E (the public Calendar API), per the test plan.
 */
class CalendarEventActionTest extends TestCase {
	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(\OCP\IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	private function action(IManager $cal, ?string $owner = 'alice', bool $enabled = true): CalendarEventAction {
		$records = $this->createMock(RecordMapper::class);
		$records->method('findOwnerById')->willReturn($owner);
		$users = $this->createMock(IUserManager::class);
		if ($owner !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('isEnabled')->willReturn($enabled);
			$users->method('get')->willReturn($user);
		} else {
			$users->method('get')->willReturn(null);
		}
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2);
		return new CalendarEventAction($cal, $records, $users, $time, new ValueInterpolator(), $relations, $this->settings(), $this->createMock(LoggerInterface::class));
	}

	private function manager(?ICreateFromString $cal): IManager {
		$mgr = $this->createMock(IManager::class);
		$mgr->method('getCalendarsForPrincipal')->willReturn($cal === null ? [] : [$cal]);
		return $mgr;
	}

	private function calendar(string $name = 'Personal', string $uri = 'personal'): ICreateFromString&\PHPUnit\Framework\MockObject\MockObject {
		$cal = $this->createMock(ICreateFromString::class);
		$cal->method('getDisplayName')->willReturn($name);
		$cal->method('getUri')->willReturn($uri);
		return $cal;
	}

	private function ctx(array $config, array $values = ['start' => '2026-07-01']): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Cal', $values, $config);
	}

	public function testNoOpWithoutTitleStartFieldOrValue(): void {
		$cal = $this->calendar();
		$cal->expects($this->never())->method('createFromString');
		$action = $this->action($this->manager($cal));
		$action->run($this->ctx(['title' => '', 'startField' => 'start']));
		$action->run($this->ctx(['title' => 'X', 'startField' => '']));
		$action->run($this->ctx(['title' => 'X', 'startField' => 'start'], [])); // no start value
	}

	public function testNoOpWhenOwnerMissingOrDisabled(): void {
		$cal = $this->calendar();
		$cal->expects($this->never())->method('createFromString');
		$this->action($this->manager($cal), null)->run($this->ctx(['title' => 'X', 'startField' => 'start']));
		$this->action($this->manager($cal), 'alice', false)->run($this->ctx(['title' => 'X', 'startField' => 'start']));
	}

	public function testNoOpWhenANamedCalendarIsNotFound(): void {
		$cal = $this->calendar('Personal', 'personal');
		$cal->expects($this->never())->method('createFromString');
		// A specific calendar name is configured that doesn't match → no fallback.
		$this->action($this->manager($cal))->run($this->ctx(['title' => 'X', 'startField' => 'start', 'calendar' => 'Team']));
		$this->assertSame('add_calendar_event', $this->action($this->manager($cal))->getType());
		$this->assertFalse($this->action($this->manager($cal))->isDeferred());
	}
}
