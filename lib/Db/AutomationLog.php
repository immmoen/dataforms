<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One automation-run log entry: the outcome of a single action the engine ran
 * for a record event.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getRecordId()
 * @method void setRecordId(?int $recordId)
 * @method int|null getAutomationId()
 * @method void setAutomationId(?int $automationId)
 * @method string getAutomationName()
 * @method void setAutomationName(string $automationName)
 * @method string getActionType()
 * @method void setActionType(string $actionType)
 * @method string getTrigger()
 * @method void setTrigger(string $trigger)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class AutomationLog extends Entity {
	protected int $registerId = 0;
	protected ?int $recordId = null;
	protected ?int $automationId = null;
	protected string $automationName = '';
	protected string $actionType = '';
	protected string $trigger = '';
	protected string $status = 'ok';
	protected ?string $message = null;
	protected int $created = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('recordId', 'integer');
		$this->addType('automationId', 'integer');
		$this->addType('created', 'integer');
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'registerId' => $this->getRegisterId(),
			'recordId' => $this->getRecordId(),
			'automationId' => $this->getAutomationId(),
			'automationName' => $this->getAutomationName(),
			'actionType' => $this->getActionType(),
			'trigger' => $this->getTrigger(),
			'status' => $this->getStatus(),
			'message' => $this->getMessage(),
			'created' => $this->getCreated(),
		];
	}
}
