<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One workflow automation: on a trigger, if the condition holds, run an action.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method string|null getCondition()
 * @method void setCondition(?string $condition)
 * @method string getActionType()
 * @method void setActionType(string $actionType)
 * @method string|null getActionConfig()
 * @method void setActionConfig(?string $actionConfig)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 */
class Automation extends Entity {
	protected int $registerId = 0;
	protected string $name = '';
	protected string $trigger = '';
	protected ?string $condition = null;
	protected string $actionType = '';
	protected ?string $actionConfig = null;
	protected bool $enabled = true;
	protected int $created = 0;
	protected int $updated = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'registerId' => $this->getRegisterId(),
			'name' => $this->getName(),
			'trigger' => $this->getTrigger(),
			'condition' => $this->decodeJson($this->getCondition(), null),
			'actionType' => $this->getActionType(),
			'actionConfig' => $this->decodeJson($this->getActionConfig(), []),
			'enabled' => $this->getEnabled(),
		];
	}

	/**
	 * Decode a stored JSON column, returning $fallback for null/empty/invalid.
	 *
	 * @param mixed $fallback
	 * @return mixed
	 */
	private function decodeJson(?string $json, $fallback) {
		if ($json === null || $json === '') {
			return $fallback;
		}
		return json_decode($json, true) ?: $fallback;
	}
}
