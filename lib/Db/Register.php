<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A register: a structured, typed data collection.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getIcon()
 * @method void setIcon(string $icon)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
class Register extends Entity implements JsonSerializable {
	protected string $title = '';
	protected ?string $description = null;
	protected string $icon = '';
	protected string $color = '';
	protected string $owner = '';
	protected int $created = 0;
	protected int $updated = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
		$this->addType('deletedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description ?? '',
			'icon' => $this->icon,
			'color' => $this->color,
			'owner' => $this->owner,
			'created' => $this->created,
			'updated' => $this->updated,
		];
	}
}
