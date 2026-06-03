<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Manages register shares. Only a manager of a register may view or change its
 * shares; all checks are delegated to RegisterService.
 */
class ShareService {
	private const VALID_BITS = Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE;

	public function __construct(
		private ShareMapper $mapper,
		private RegisterService $registerService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function listForRegister(string $userId, int $registerId): array {
		$register = $this->registerService->findManageable($userId, $registerId);
		$shares = array_map(static fn (Share $s) => $s->jsonSerialize(), $this->mapper->findByRegister($registerId));
		// Surface the owner as a read-only entry so the UI can show "Owner".
		array_unshift($shares, [
			'id' => 0,
			'registerId' => $registerId,
			'shareType' => Share::TYPE_USER,
			'shareTypeName' => 'user',
			'shareWith' => $register->getOwner(),
			'permissions' => self::VALID_BITS,
			'isOwner' => true,
		]);
		return $shares;
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function add(string $userId, int $registerId, string $shareTypeName, string $shareWith, int $permissions): Share {
		$register = $this->registerService->findManageable($userId, $registerId);

		$shareType = $shareTypeName === 'group' ? Share::TYPE_GROUP : Share::TYPE_USER;
		$shareWith = trim($shareWith);
		if ($shareWith === '') {
			throw new ValidationException('Pick a user or group to share with');
		}
		if ($shareType === Share::TYPE_USER && !$this->userManager->userExists($shareWith)) {
			throw new ValidationException('Unknown user: ' . $shareWith);
		}
		if ($shareType === Share::TYPE_GROUP && !$this->groupManager->groupExists($shareWith)) {
			throw new ValidationException('Unknown group: ' . $shareWith);
		}
		if ($shareType === Share::TYPE_USER && $shareWith === $register->getOwner()) {
			throw new ValidationException('The owner already has full access');
		}

		$permissions = $this->normalisePermissions($permissions);

		$existing = $this->mapper->findExisting($registerId, $shareType, $shareWith);
		if ($existing !== null) {
			$existing->setPermissions($permissions);
			return $this->mapper->update($existing);
		}

		$share = new Share();
		$share->setRegisterId($registerId);
		$share->setShareType($shareType);
		$share->setShareWith($shareWith);
		$share->setPermissions($permissions);
		$share->setCreated($this->time->getTime());
		return $this->mapper->insert($share);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function setPermissions(string $userId, int $shareId, int $permissions): Share {
		$share = $this->findManageableShare($userId, $shareId);
		$share->setPermissions($this->normalisePermissions($permissions));
		return $this->mapper->update($share);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function remove(string $userId, int $shareId): void {
		$share = $this->findManageableShare($userId, $shareId);
		$this->mapper->delete($share);
	}

	private function findManageableShare(string $userId, int $shareId): Share {
		try {
			$share = $this->mapper->find($shareId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Share not found');
		}
		$this->registerService->findManageable($userId, $share->getRegisterId());
		return $share;
	}

	/**
	 * Clamp to valid bits and always include read (you cannot write/manage
	 * without being able to read).
	 */
	private function normalisePermissions(int $permissions): int {
		$permissions &= self::VALID_BITS;
		if ($permissions === 0) {
			$permissions = Share::PERMISSION_READ;
		}
		return $permissions | Share::PERMISSION_READ;
	}
}
