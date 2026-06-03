<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A register share. permissions is a bitmask: 1=read, 2=write, 4=manage.
 * share_type: 0=user, 1=group (mirrors Nextcloud's convention).
 *
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int getShareType()
 * @method void setShareType(int $shareType)
 * @method string getShareWith()
 * @method void setShareWith(string $shareWith)
 * @method int getPermissions()
 * @method void setPermissions(int $permissions)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class Share extends Entity implements JsonSerializable {
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;

	public const PERMISSION_READ = 1;
	public const PERMISSION_WRITE = 2;
	public const PERMISSION_MANAGE = 4;

	protected int $registerId = 0;
	protected int $shareType = self::TYPE_USER;
	protected string $shareWith = '';
	protected int $permissions = self::PERMISSION_READ;
	protected int $created = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('registerId', 'integer');
		$this->addType('shareType', 'integer');
		$this->addType('permissions', 'integer');
		$this->addType('created', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'registerId' => $this->registerId,
			'shareType' => $this->shareType,
			'shareTypeName' => $this->shareType === self::TYPE_GROUP ? 'group' : 'user',
			'shareWith' => $this->shareWith,
			'permissions' => $this->permissions,
			'canRead' => (bool)($this->permissions & self::PERMISSION_READ),
			'canWrite' => (bool)($this->permissions & self::PERMISSION_WRITE),
			'canManage' => (bool)($this->permissions & self::PERMISSION_MANAGE),
			'created' => $this->created,
		];
	}
}
