<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCP\IAppConfig;

/**
 * Instance-wide, admin-configurable settings for the automation engine: which
 * action types are available, and the operational limits/defaults that used to
 * be hardcoded constants in the action classes. Everything has a sane default
 * (the previous constant), so an un-configured instance behaves exactly as
 * before. Stored via IAppConfig under the 'dataforms' app.
 *
 * Protocol internals (API paths, Talk room type, path-safety rules) are
 * deliberately NOT here — they are correctness-critical, not preferences.
 */
class WorkflowSettings {

	private const APP = 'dataforms';

	/** Default operational limits/defaults (the former hardcoded constants). */
	public const DEFAULTS = [
		'maxFolders' => 50,
		'maxCreated' => 200,
		'maxTemplateFiles' => 200,
		'maxParticipants' => 100,
		'maxDeckColumns' => 20,
		'calendarDefaultDuration' => 60,
		'outboundTimeout' => 10,
	];

	public const DEFAULT_DECK_COLUMNS = ['To do', 'Doing', 'Done'];

	/** Action types that require the cross-app service account to be configured. */
	public const SERVICE_ACCOUNT_ACTIONS = ['create_talk_room', 'create_deck_board'];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	// ---- action enablement ----------------------------------------------

	/**
	 * @return string[] action type ids an admin has disabled
	 */
	public function disabledActions(): array {
		$raw = $this->appConfig->getValueString(self::APP, 'automation_disabled', '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded)
			? array_values(array_filter(array_map('strval', $decoded), static fn ($s) => $s !== ''))
			: [];
	}

	/**
	 * @param string[] $types
	 */
	public function setDisabledActions(array $types): void {
		$clean = array_values(array_unique(array_filter(array_map('strval', $types), static fn ($s) => $s !== '')));
		$this->appConfig->setValueString(self::APP, 'automation_disabled', json_encode($clean, JSON_THROW_ON_ERROR));
	}

	/** Whether an admin has left this action type enabled (independent of the service account). */
	public function isActionEnabled(string $type): bool {
		return !in_array($type, $this->disabledActions(), true);
	}

	/** Whether this action type needs the cross-app service account to function. */
	public function needsServiceAccount(string $type): bool {
		return in_array($type, self::SERVICE_ACCOUNT_ACTIONS, true);
	}

	/**
	 * The action types actually usable right now: enabled by the admin, and — for
	 * the cross-app actions — only when the service account is configured.
	 *
	 * @param string[] $allTypes the full catalog (from ActionRegistry)
	 * @return string[]
	 */
	public function availableActions(array $allTypes, bool $serviceAccountConfigured): array {
		$disabled = $this->disabledActions();
		return array_values(array_filter($allTypes, function (string $type) use ($disabled, $serviceAccountConfigured): bool {
			if (in_array($type, $disabled, true)) {
				return false;
			}
			if ($this->needsServiceAccount($type) && !$serviceAccountConfigured) {
				return false;
			}
			return true;
		}));
	}

	// ---- operational limits / defaults ----------------------------------

	public function maxFolders(): int {
		return $this->positiveInt('automation_max_folders', self::DEFAULTS['maxFolders']);
	}

	public function maxCreated(): int {
		return $this->positiveInt('automation_max_created', self::DEFAULTS['maxCreated']);
	}

	public function maxTemplateFiles(): int {
		return $this->positiveInt('automation_max_template_files', self::DEFAULTS['maxTemplateFiles']);
	}

	public function maxParticipants(): int {
		return $this->positiveInt('automation_max_participants', self::DEFAULTS['maxParticipants']);
	}

	public function maxDeckColumns(): int {
		return $this->positiveInt('automation_max_deck_columns', self::DEFAULTS['maxDeckColumns']);
	}

	public function calendarDefaultDuration(): int {
		return $this->positiveInt('automation_calendar_duration', self::DEFAULTS['calendarDefaultDuration']);
	}

	public function outboundTimeout(): int {
		return $this->positiveInt('automation_outbound_timeout', self::DEFAULTS['outboundTimeout']);
	}

	/**
	 * @return string[] default Deck columns when an action doesn't specify its own
	 */
	public function defaultDeckColumns(): array {
		$raw = $this->appConfig->getValueString(self::APP, 'automation_deck_columns', '');
		if (trim($raw) === '') {
			return self::DEFAULT_DECK_COLUMNS;
		}
		$cols = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
		return $cols !== [] ? $cols : self::DEFAULT_DECK_COLUMNS;
	}

	// ---- admin read/write -----------------------------------------------

	/**
	 * Snapshot for the admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return [
			'disabled' => $this->disabledActions(),
			'limits' => [
				'maxFolders' => $this->maxFolders(),
				'maxCreated' => $this->maxCreated(),
				'maxTemplateFiles' => $this->maxTemplateFiles(),
				'maxParticipants' => $this->maxParticipants(),
				'maxDeckColumns' => $this->maxDeckColumns(),
				'calendarDefaultDuration' => $this->calendarDefaultDuration(),
				'outboundTimeout' => $this->outboundTimeout(),
			],
			'deckColumns' => implode(', ', $this->defaultDeckColumns()),
			'defaults' => self::DEFAULTS,
		];
	}

	/**
	 * Persist limits from the admin form. Unknown keys are ignored; a missing or
	 * non-positive value resets that limit to its default.
	 *
	 * @param array<string,mixed> $limits
	 */
	public function setLimits(array $limits): void {
		$map = [
			'maxFolders' => 'automation_max_folders',
			'maxCreated' => 'automation_max_created',
			'maxTemplateFiles' => 'automation_max_template_files',
			'maxParticipants' => 'automation_max_participants',
			'maxDeckColumns' => 'automation_max_deck_columns',
			'calendarDefaultDuration' => 'automation_calendar_duration',
			'outboundTimeout' => 'automation_outbound_timeout',
		];
		foreach ($map as $field => $key) {
			if (!array_key_exists($field, $limits)) {
				continue;
			}
			$value = (int)$limits[$field];
			if ($value > 0 && $value !== self::DEFAULTS[$field]) {
				$this->appConfig->setValueInt(self::APP, $key, $value);
			} else {
				$this->appConfig->deleteKey(self::APP, $key); // reset to default
			}
		}
	}

	public function setDeckColumns(string $columns): void {
		$cols = array_values(array_filter(array_map('trim', explode(',', $columns)), static fn ($s) => $s !== ''));
		if ($cols === [] || $cols === self::DEFAULT_DECK_COLUMNS) {
			$this->appConfig->deleteKey(self::APP, 'automation_deck_columns');
			return;
		}
		$this->appConfig->setValueString(self::APP, 'automation_deck_columns', implode(', ', array_slice($cols, 0, 20)));
	}

	private function positiveInt(string $key, int $default): int {
		$value = $this->appConfig->getValueInt(self::APP, $key, $default);
		return $value > 0 ? $value : $default;
	}
}
