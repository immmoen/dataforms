<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A typed field in a register's schema.
 *
 * `config` is an opaque JSON blob (options, min/max, precision, ...) that is
 * never queried by value, so it stays portable. Record DATA never lives here.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getMachineName()
 * @method void setMachineName(string $machineName)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getConfig()
 * @method void setConfig(?string $config)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method bool|null getMandatory()
 * @method void setMandatory(?bool $mandatory)
 * @method bool|null getIsUnique()
 * @method void setIsUnique(?bool $isUnique)
 * @method string|null getDefaultValue()
 * @method void setDefaultValue(?string $defaultValue)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
class Field extends Entity implements JsonSerializable {
	protected int $registerId = 0;
	protected string $machineName = '';
	protected string $label = '';
	protected string $type = '';
	protected ?string $config = null;
	protected int $position = 0;
	protected ?bool $mandatory = false;
	protected ?bool $isUnique = false;
	protected ?string $defaultValue = null;
	// Soft-delete tombstone: when set, the field is retired but its row (and
	// machine_name) is kept so the name stays reserved (audit M2).
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('mandatory', 'boolean');
		$this->addType('isUnique', 'boolean');
		$this->addType('deletedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'registerId' => $this->registerId,
			'machineName' => $this->machineName,
			'label' => $this->label,
			'type' => $this->type,
			'config' => json_decode($this->config ?? '{}', true) ?: [],
			'position' => $this->position,
			'mandatory' => (bool)$this->mandatory,
			'unique' => (bool)$this->isUnique,
			'default' => $this->defaultValue,
		];
	}
}
