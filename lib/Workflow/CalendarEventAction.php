<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * Adds an event to one of the record owner's calendars when an automation fires.
 * A "guided" cross-app action built entirely on Nextcloud's PUBLIC calendar API
 * (OCP\Calendar) — no dependency on the Calendar UI app, no fragile coupling.
 *
 * The start comes from a date/datetime field on the record; the title/description
 * are {machineName} templates. Like the other provisioning actions it runs as the
 * record OWNER (not whoever triggered the event), is deferred (off the request
 * thread), and is idempotent: the event UID is derived from the record +
 * automation, so re-firing reuses the same event instead of duplicating it.
 *
 * action_config: { calendar?: string, title: string, startField: string,
 *                  durationMinutes?: int, allDay?: bool, description?: string }.
 */
class CalendarEventAction implements IAction {

	private const MAX_TITLE = 255;

	public function __construct(
		private IManager $calendarManager,
		private RecordMapper $recordMapper,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private ValueInterpolator $interpolator,
		private RelationResolver $relationResolver,
		private WorkflowSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'add_calendar_event';
	}

	public function isDeferred(): bool {
		return true; // calendar write: run off the request thread
	}

	public function run(ActionContext $context): void {
		$titleTpl = trim((string)($context->config['title'] ?? ''));
		$startField = (string)($context->config['startField'] ?? '');
		if ($titleTpl === '' || $startField === '') {
			return;
		}
		$startRaw = trim((string)($context->values[$startField] ?? ''));
		if ($startRaw === '') {
			return; // no start value on this record → nothing to schedule
		}

		// Act as the record OWNER, not the user who triggered the event.
		$owner = $this->recordMapper->findOwnerById($context->recordId);
		if ($owner === null || $owner === '') {
			return;
		}
		$user = $this->userManager->get($owner);
		if ($user === null || !$user->isEnabled()) {
			return;
		}

		$values = $this->relationResolver->enrich($owner, $context->registerId, $context->values);
		$title = $this->interpolator->interpolate($titleTpl, $values);
		if ($title === '') {
			return;
		}

		$start = $this->parseStart($startRaw);
		if ($start === null) {
			$this->logger->warning('Dataforms calendar action: unparseable start "' . $startRaw . '"');
			return;
		}
		[$startDt, $allDay] = $start;

		$calendar = $this->pickCalendar($owner, trim((string)($context->config['calendar'] ?? '')));
		if ($calendar === null) {
			$this->logger->warning('Dataforms calendar action: no writable calendar for ' . $owner);
			return;
		}

		$ics = $this->buildIcs(
			$context,
			mb_substr($title, 0, self::MAX_TITLE),
			$this->interpolator->interpolate((string)($context->config['description'] ?? ''), $values),
			$startDt,
			$allDay,
			max(0, (int)($context->config['durationMinutes'] ?? $this->settings->calendarDefaultDuration())),
			(bool)($context->config['allDay'] ?? false) || $allDay,
		);

		try {
			$calendar->createFromString($this->uid($context) . '.ics', $ics);
			$this->logger->info('Dataforms calendar event created for record ' . $context->recordId);
		} catch (\Throwable $e) {
			// A conflict on the deterministic UID means it already exists → idempotent.
			$this->logger->info('Dataforms calendar action: event not created (likely already present)', ['exception' => $e]);
		}
	}

	/** Deterministic per (record, automation) so re-fires never duplicate. */
	private function uid(ActionContext $context): string {
		return 'df-' . substr(md5($context->recordId . '|' . $context->automationName), 0, 24) . '@dataforms';
	}

	/**
	 * @return array{0:\DateTimeImmutable,1:bool}|null [start, isAllDay]
	 */
	private function parseStart(string $raw): ?array {
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
				return [new \DateTimeImmutable($raw . ' 00:00:00'), true];
			}
			return [new \DateTimeImmutable($raw), false];
		} catch (\Throwable) {
			return null;
		}
	}

	private function buildIcs(ActionContext $context, string $title, string $description, \DateTimeImmutable $start, bool $parsedAllDay, int $durationMinutes, bool $allDay): string {
		$vcal = new VCalendar();
		$vevent = $vcal->add('VEVENT', [
			'UID' => $this->uid($context),
			'SUMMARY' => $title,
		]);
		$vevent->add('DTSTAMP', (new \DateTimeImmutable('@' . $this->time->getTime())));
		if ($allDay) {
			$vevent->add('DTSTART', $start, ['VALUE' => 'DATE']);
			$vevent->add('DTEND', $start->modify('+1 day'), ['VALUE' => 'DATE']);
		} else {
			$vevent->add('DTSTART', $start);
			$vevent->add('DTEND', $start->modify('+' . ($durationMinutes > 0 ? $durationMinutes : $this->settings->calendarDefaultDuration()) . ' minutes'));
		}
		if ($description !== '') {
			$vevent->add('DESCRIPTION', $description);
		}
		return $vcal->serialize();
	}

	/**
	 * Pick a writable calendar from the owner's set: by display name if one was
	 * configured (else null if that named calendar isn't found — never guess), or
	 * the owner's default "personal" calendar when no name is configured.
	 */
	private function pickCalendar(string $owner, string $wantedName): ?ICreateFromString {
		$personal = null;
		$first = null;
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $owner) as $cal) {
			if (!$cal instanceof ICreateFromString) {
				continue;
			}
			if ($wantedName !== '' && mb_strtolower((string)$cal->getDisplayName()) === mb_strtolower($wantedName)) {
				return $cal;
			}
			if ($cal->getUri() === 'personal') {
				$personal = $cal;
			}
			$first ??= $cal;
		}
		if ($wantedName !== '') {
			return null; // a name was asked for but not found — do not fall back silently
		}
		return $personal ?? $first;
	}

}
