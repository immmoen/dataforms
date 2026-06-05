<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One audit-history entry for a record action.
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int getRecordId()
 * @method void setRecordId(int $recordId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class History extends Entity {
	protected int $registerId = 0;
	protected int $recordId = 0;
	protected string $userId = '';
	protected string $action = '';
	protected ?string $summary = null;
	protected ?string $detail = null;
	protected int $created = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('recordId', 'integer');
		$this->addType('created', 'integer');
	}
}
