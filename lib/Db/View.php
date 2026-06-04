<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A saved view: a named combination of columns, filters, sort and search for a
 * register's records. `definition` is opaque JSON.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method bool|null getShared()
 * @method void setShared(?bool $shared)
 * @method string|null getDefinition()
 * @method void setDefinition(?string $definition)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 */
class View extends Entity implements JsonSerializable {
	protected int $registerId = 0;
	protected string $title = '';
	protected string $owner = '';
	protected ?bool $shared = false;
	protected ?string $definition = null;
	protected int $created = 0;
	protected int $updated = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('shared', 'boolean');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
	}

	public function jsonSerialize(): array {
		$def = json_decode($this->definition ?? '{}', true) ?: [];
		return [
			'id' => $this->id,
			'registerId' => $this->registerId,
			'title' => $this->title,
			'owner' => $this->owner,
			'shared' => (bool)$this->shared,
			'definition' => [
				'columns' => $def['columns'] ?? [],
				'filters' => $def['filters'] ?? [],
				'sort' => $def['sort'] ?? 'updated',
				'direction' => $def['direction'] ?? 'DESC',
				'search' => $def['search'] ?? '',
			],
		];
	}
}
