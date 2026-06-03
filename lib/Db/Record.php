<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A stored record (one entry) in a register. Its field values live in
 * df_record_values; this entity holds only metadata.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 */
class Record extends Entity {
	protected int $registerId = 0;
	protected string $owner = '';
	protected string $createdBy = '';
	protected int $created = 0;
	protected int $updated = 0;
	protected ?int $deletedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
		$this->addType('deletedAt', 'integer');
	}
}
