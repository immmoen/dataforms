<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A data-entry form bound to a register. `definition` is opaque JSON:
 * { sections: [ { title, fields: [machineName, ...] } ] }.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDefinition()
 * @method void setDefinition(?string $definition)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 */
class Form extends Entity implements JsonSerializable {
	protected int $registerId = 0;
	protected string $title = '';
	protected ?string $definition = null;
	protected int $position = 0;
	protected int $created = 0;
	protected int $updated = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('position', 'integer');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
	}

	public function jsonSerialize(): array {
		$def = json_decode($this->definition ?? '{}', true) ?: [];
		return [
			'id' => $this->id,
			'registerId' => $this->registerId,
			'title' => $this->title,
			'definition' => [
				'sections' => $def['sections'] ?? [],
			],
			'position' => $this->position,
		];
	}
}
