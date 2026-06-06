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
		private ITimeFactory $time,
	) {
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
		$this->validateActionConfig($actionType, $data['actionConfig'] ?? []);

		$a = new Automation();
		$a->setRegisterId($registerId);
		$a->setName(trim((string)($data['name'] ?? '')) ?: 'Automation');
		$a->setTrigger($this->validTrigger($data['trigger'] ?? ''));
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
			$a->setActionType($this->validAction($changes['actionType']));
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
		// Re-validate the (possibly newly combined) action type + config.
		$this->validateActionConfig(
			$a->getActionType(),
			$a->getActionConfig() ? (json_decode((string)$a->getActionConfig(), true) ?: []) : []
		);
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
	 * Lightweight save-time sanity check on an action's config. The authoritative
	 * SSRF defence lives in WebhookAction (it refuses local addresses and
	 * redirects at call time); this just rejects an obviously-wrong webhook URL
	 * early, for a clear error in the builder UI.
	 *
	 * @throws ValidationException
	 */
	private function validateActionConfig(string $actionType, mixed $config): void {
		if ($actionType !== 'webhook') {
			return;
		}
		$arr = is_array($config) ? $config : [];
		$url = trim((string)($arr['url'] ?? ''));
		if ($url !== '' && !preg_match('#^https?://#i', $url)) {
			throw new ValidationException('Webhook URL must start with http:// or https://');
		}
	}

	private function encodeJson(mixed $value): ?string {
		if ($value === null || $value === []) {
			return null;
		}
		return json_encode($value, JSON_THROW_ON_ERROR);
	}
}
