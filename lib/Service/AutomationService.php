<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Db\AutomationMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Workflow automations. Viewing/editing needs Manage on the register (like rules
 * and forms). The listener reads them internally via findActive().
 */
class AutomationService {
	public const TRIGGERS = ['create', 'update', 'delete'];

	public function __construct(
		private AutomationMapper $mapper,
		private RegisterService $registerService,
		private ActionRegistry $actionRegistry,
		private WorkflowSettings $workflowSettings,
		private ServiceAccountService $serviceAccount,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Action types managers may actually pick right now: those an admin has left
	 * enabled, with the cross-app (Talk/Deck) actions hidden until the service
	 * account is configured. Drives the builder's action dropdown.
	 *
	 * @return string[]
	 */
	public function availableActionTypes(): array {
		return $this->workflowSettings->availableActions(
			$this->actionRegistry->types(),
			$this->serviceAccount->anyConfigured(),
		);
	}

	/**
	 * Configured service accounts the builder offers for the Talk/Deck actions.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public function serviceAccounts(): array {
		return $this->serviceAccount->accountList();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function listForRegister(string $userId, int $registerId): array {
		$this->registerService->findManageable($userId, $registerId);
		return array_map(static fn (Automation $a) => $a->jsonSerialize(), $this->mapper->findByRegister($registerId));
	}

	/**
	 * @param array<string,mixed> $data
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, array $data): array {
		$this->registerService->findManageable($userId, $registerId);

		$actionType = $this->validAction($data['actionType'] ?? '');
		$this->assertActionEnabled($actionType);
		$this->validateActionConfig($actionType, $data['actionConfig'] ?? []);
		$trigger = $this->validTrigger($data['trigger'] ?? '');
		$this->assertTriggerAllowed($trigger, $actionType);

		$a = new Automation();
		$a->setRegisterId($registerId);
		$a->setName(trim((string)($data['name'] ?? '')) ?: 'Automation');
		$a->setTrigger($trigger);
		$a->setActionType($actionType);
		$a->setCondition($this->encodeJson($data['condition'] ?? null));
		$a->setActionConfig($this->encodeJson($data['actionConfig'] ?? []));
		$a->setEnabled((bool)($data['enabled'] ?? true));
		$now = $this->time->getTime();
		$a->setCreated($now);
		$a->setUpdated($now);
		return $this->mapper->insert($a)->jsonSerialize();
	}

	/**
	 * @param array<string,mixed> $changes
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function update(string $userId, int $id, array $changes): array {
		$a = $this->findManageable($userId, $id);
		if (array_key_exists('name', $changes)) {
			$a->setName(trim((string)$changes['name']) ?: $a->getName());
		}
		if (array_key_exists('trigger', $changes)) {
			$a->setTrigger($this->validTrigger($changes['trigger']));
		}
		if (array_key_exists('actionType', $changes)) {
			$type = $this->validAction($changes['actionType']);
			$this->assertActionEnabled($type);
			$a->setActionType($type);
		}
		if (array_key_exists('condition', $changes)) {
			$a->setCondition($this->encodeJson($changes['condition']));
		}
		if (array_key_exists('actionConfig', $changes)) {
			$a->setActionConfig($this->encodeJson($changes['actionConfig']));
		}
		if (array_key_exists('enabled', $changes)) {
			$a->setEnabled((bool)$changes['enabled']);
		}
		// Re-validate the (possibly newly combined) action type + config + trigger.
		$cfgJson = $a->getActionConfig();
		$this->validateActionConfig(
			$a->getActionType(),
			($cfgJson !== null && $cfgJson !== '') ? (json_decode($cfgJson, true) ?: []) : []
		);
		$this->assertTriggerAllowed($a->getTrigger(), $a->getActionType());
		$a->setUpdated($this->time->getTime());
		return $this->mapper->update($a)->jsonSerialize();
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$a = $this->findManageable($userId, $id);
		$this->mapper->delete($a);
	}

	/**
	 * Internal: enabled automations for a register + trigger.
	 *
	 * @return Automation[]
	 */
	public function findActive(int $registerId, string $trigger): array {
		return $this->mapper->findActive($registerId, $trigger);
	}

	// ---- helpers ---------------------------------------------------------

	private function findManageable(string $userId, int $id): Automation {
		try {
			$a = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Automation not found');
		}
		$this->registerService->findManageable($userId, $a->getRegisterId());
		return $a;
	}

	/**
	 * Provisioning actions that make non-idempotent external side effects (a Talk
	 * room, a Deck board) may only run on the 'create' trigger, so a record
	 * provisions its workspace exactly once and repeated edits can't re-fire them.
	 *
	 * @throws ValidationException
	 */
	private function assertTriggerAllowed(string $trigger, string $actionType): void {
		$createOnly = ['create_talk_room', 'create_deck_board'];
		if (in_array($actionType, $createOnly, true) && $trigger !== 'create') {
			throw new ValidationException('This action can only run when a record is created');
		}
	}

	private function validTrigger(mixed $trigger): string {
		$t = (string)$trigger;
		if (!in_array($t, self::TRIGGERS, true)) {
			throw new ValidationException('Unknown trigger: ' . $t);
		}
		return $t;
	}

	private function validAction(mixed $type): string {
		$t = (string)$type;
		if ($this->actionRegistry->get($t) === null) {
			throw new ValidationException('Unknown action: ' . $t);
		}
		return $t;
	}

	/**
	 * Refuse an action type an administrator has disabled instance-wide, so a
	 * manager can't create (or switch an automation to) an action the admin has
	 * turned off in Settings → Administration → DataForms.
	 *
	 * @throws ValidationException
	 */
	private function assertActionEnabled(string $actionType): void {
		if (!$this->workflowSettings->isActionEnabled($actionType)) {
			throw new ValidationException('This action is disabled by the administrator');
		}
	}

	/**
	 * Lightweight save-time sanity check on an action's config. The authoritative
	 * SSRF defence lives in WebhookAction (it refuses local addresses and
	 * redirects at call time); this just rejects an obviously-wrong webhook URL
	 * early, for a clear error in the builder UI.
	 *
	 * @throws ValidationException
	 */
	private function validateActionConfig(string $actionType, mixed $config): void {
		$arr = is_array($config) ? $config : [];
		if ($actionType === 'webhook') {
			$url = trim((string)($arr['url'] ?? ''));
			if ($url !== '' && !preg_match('#^https?://#i', $url)) {
				throw new ValidationException('Webhook URL must start with http:// or https://');
			}
		}
		if ($actionType === 'provision_folders') {
			$folders = array_filter(array_map('strval', (array)($arr['folders'] ?? [])), static fn ($s) => trim($s) !== '');
			if ($folders === []) {
				throw new ValidationException('Add at least one folder to create');
			}
			$maxFolders = $this->workflowSettings->maxFolders();
			if (count($folders) > $maxFolders) {
				throw new ValidationException('Too many folders to create (max ' . $maxFolders . ')');
			}
			// Validate the optional base folder at save time so a bad path surfaces
			// in the builder instead of failing silently in the background job.
			foreach (explode('/', str_replace('\\', '/', (string)($arr['basePath'] ?? ''))) as $seg) {
				$seg = trim($seg);
				if ($seg === '') {
					continue;
				}
				if (trim($seg, '.') === '' || preg_match('#[<>:"|?*\x00-\x1F]#', $seg)) {
					throw new ValidationException('The base folder contains an invalid path');
				}
			}
		}
		if ($actionType === 'apply_template') {
			if (trim((string)($arr['source'] ?? '')) === '' || trim((string)($arr['destination'] ?? '')) === '') {
				throw new ValidationException('Set both the template folder and the destination');
			}
		}
		if ($actionType === 'create_talk_room' && trim((string)($arr['roomName'] ?? '')) === '') {
			throw new ValidationException('The conversation needs a name');
		}
		if ($actionType === 'create_deck_board' && trim((string)($arr['title'] ?? '')) === '') {
			throw new ValidationException('The board needs a title');
		}
		if ($actionType === 'create_talk_room' || $actionType === 'create_deck_board') {
			// If a specific service account was chosen, it must still exist — surface
			// a stale/removed selection at save time instead of silently no-op'ing.
			$sa = trim((string)($arr['serviceAccount'] ?? ''));
			if ($sa !== '' && $sa !== ServiceAccountService::DEFAULT_ID
				&& !in_array($sa, array_column($this->serviceAccount->accountList(), 'id'), true)) {
				throw new ValidationException('The selected service account no longer exists');
			}
		}
		if ($actionType === 'add_calendar_event') {
			if (trim((string)($arr['title'] ?? '')) === '') {
				throw new ValidationException('The event needs a title');
			}
			if (trim((string)($arr['startField'] ?? '')) === '') {
				throw new ValidationException('Choose a date field for the event start');
			}
		}
	}

	private function encodeJson(mixed $value): ?string {
		if ($value === null || $value === []) {
			return null;
		}
		return json_encode($value, JSON_THROW_ON_ERROR);
	}
}
