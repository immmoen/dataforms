<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Rule;
use OCA\Dataforms\Db\RuleMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

class RuleService {
	private const EFFECTS = ['show', 'require', 'set_value', 'validate', 'compute'];

	public function __construct(
		private RuleMapper $mapper,
		private RegisterService $registerService,
	) {
	}

	/**
	 * @return Rule[]
	 * @throws NotFoundException
	 */
	public function listForRegister(string $userId, int $registerId): array {
		$this->registerService->find($userId, $registerId);
		return $this->mapper->findByRegister($registerId);
	}

	/**
	 * Plain rule arrays (jsonSerialize), for the evaluator.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function definitionsForRegister(int $registerId): array {
		$out = [];
		foreach ($this->mapper->findByRegister($registerId) as $rule) {
			$data = $rule->jsonSerialize();
			if ($data['enabled']) {
				$out[] = $data;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $data
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, array $data): Rule {
		$this->registerService->findManageable($userId, $registerId);
		$effect = (string)($data['effect'] ?? '');
		if (!in_array($effect, self::EFFECTS, true)) {
			throw new ValidationException('Unknown rule effect: ' . $effect);
		}
		if (trim((string)($data['target'] ?? '')) === '') {
			throw new ValidationException('A target field is required');
		}

		$rule = new Rule();
		$rule->setRegisterId($registerId);
		$rule->setEffect($effect);
		$rule->setTarget((string)$data['target']);
		$rule->setDefinition($this->encodeDefinition($data));
		$rule->setPosition((int)($data['position'] ?? 0));
		$rule->setEnabled($data['enabled'] ?? true ? true : false);
		return $this->mapper->insert($rule);
	}

	/**
	 * @param array<string,mixed> $data
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function update(string $userId, int $id, array $data): Rule {
		$rule = $this->findOwned($userId, $id);
		if (isset($data['target']) && trim((string)$data['target']) !== '') {
			$rule->setTarget((string)$data['target']);
		}
		if (isset($data['effect']) && in_array($data['effect'], self::EFFECTS, true)) {
			$rule->setEffect((string)$data['effect']);
		}
		$rule->setDefinition($this->encodeDefinition($data));
		if (array_key_exists('enabled', $data)) {
			$rule->setEnabled($data['enabled'] ? true : false);
		}
		if (array_key_exists('position', $data)) {
			$rule->setPosition((int)$data['position']);
		}
		return $this->mapper->update($rule);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$rule = $this->findOwned($userId, $id);
		$this->mapper->delete($rule);
	}

	private function findOwned(string $userId, int $id): Rule {
		try {
			$rule = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Rule not found');
		}
		$this->registerService->findManageable($userId, $rule->getRegisterId());
		return $rule;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function encodeDefinition(array $data): string {
		$def = [
			'conditions' => $data['conditions'] ?? null,
			'value' => $data['value'] ?? null,
			'expression' => $data['expression'] ?? null,
			'validation' => $data['validation'] ?? null,
		];
		return json_encode($def, JSON_THROW_ON_ERROR);
	}
}
