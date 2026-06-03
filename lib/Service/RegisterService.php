<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Business logic for registers. All access decisions are made here,
 * server-side; controllers never trust client-supplied ownership.
 *
 * Phase 1: owner has full rights; shared users get read access. Finer-grained
 * write/manage sharing is layered on with the share UI in a later slice.
 */
class RegisterService {
	public function __construct(
		private RegisterMapper $mapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return Register[]
	 */
	public function findAll(string $userId): array {
		return $this->mapper->findAllForUser($userId, $this->groupIdsOf($userId));
	}

	/**
	 * @throws NotFoundException when the register is missing or not visible.
	 */
	public function find(string $userId, int $id): Register {
		try {
			$register = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Register not found');
		}
		if (!$this->canRead($register, $userId)) {
			throw new NotFoundException('Register not found');
		}
		return $register;
	}

	public function create(string $userId, string $title, string $description = '', string $icon = '', string $color = ''): Register {
		$now = $this->time->getTime();
		$register = new Register();
		$register->setTitle($title);
		$register->setDescription($description);
		$register->setIcon($icon);
		$register->setColor($color);
		$register->setOwner($userId);
		$register->setCreated($now);
		$register->setUpdated($now);
		return $this->mapper->insert($register);
	}

	/**
	 * @param array<string,string> $changes title/description/icon/color
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	/**
	 * Find a register the user is allowed to manage (owner). Used by other
	 * services (fields, records) to gate schema/data changes.
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function findManageable(string $userId, int $id): Register {
		$register = $this->find($userId, $id);
		$this->requireManage($register, $userId);
		return $register;
	}

	public function update(string $userId, int $id, array $changes): Register {
		$register = $this->findManageable($userId, $id);

		if (array_key_exists('title', $changes)) {
			$register->setTitle($changes['title']);
		}
		if (array_key_exists('description', $changes)) {
			$register->setDescription($changes['description']);
		}
		if (array_key_exists('icon', $changes)) {
			$register->setIcon($changes['icon']);
		}
		if (array_key_exists('color', $changes)) {
			$register->setColor($changes['color']);
		}
		$register->setUpdated($this->time->getTime());
		return $this->mapper->update($register);
	}

	/**
	 * Soft-delete: sets deleted_at so the register drops out of all listings.
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$register = $this->findManageable($userId, $id);
		$register->setDeletedAt($this->time->getTime());
		$this->mapper->update($register);
	}

	// ---- access control --------------------------------------------------

	private function canRead(Register $register, string $userId): bool {
		if ($register->getOwner() === $userId) {
			return true;
		}
		// Visible if it surfaces through the shared listing.
		foreach ($this->mapper->findAllForUser($userId, $this->groupIdsOf($userId)) as $r) {
			if ($r->getId() === $register->getId()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @throws ForbiddenException
	 */
	private function requireManage(Register $register, string $userId): void {
		if ($register->getOwner() !== $userId) {
			throw new ForbiddenException('Only the owner can manage this register');
		}
	}

	/**
	 * @return string[]
	 */
	private function groupIdsOf(string $userId): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}
		return $this->groupManager->getUserGroupIds($user);
	}
}
