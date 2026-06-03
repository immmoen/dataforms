<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A conditional rule. `definition` is opaque JSON holding the condition tree
 * and effect payload (value / expression / validation).
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getEffect()
 * @method void setEffect(string $effect)
 * @method string getTarget()
 * @method void setTarget(string $target)
 * @method string|null getDefinition()
 * @method void setDefinition(?string $definition)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 */
class Rule extends Entity implements JsonSerializable {
	protected int $registerId = 0;
	protected string $effect = '';
	protected string $target = '';
	protected ?string $definition = null;
	protected int $position = 0;
	protected ?bool $enabled = true;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('enabled', 'boolean');
	}

	public function jsonSerialize(): array {
		$def = json_decode($this->definition ?? '{}', true) ?: [];
		return [
			'id' => $this->id,
			'registerId' => $this->registerId,
			'effect' => $this->effect,
			'target' => $this->target,
			'conditions' => $def['conditions'] ?? null,
			'value' => $def['value'] ?? null,
			'expression' => $def['expression'] ?? null,
			'validation' => $def['validation'] ?? null,
			'position' => $this->position,
			'enabled' => $this->enabled === null ? true : (bool)$this->enabled,
		];
	}
}
