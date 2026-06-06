<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\FormMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Business logic for registers. All access decisions are made here,
 * server-side; controllers never trust client-supplied ownership.
 *
 * Permissions are a bitmask: read (1), write (2), manage (4). The owner always
 * has all three. Other users get the OR of permissions from shares to them or
 * their groups.
 */
class RegisterService {
	public function __construct(
		private RegisterMapper $mapper,
		private ShareMapper $shareMapper,
		private ViewMapper $viewMapper,
		private FormMapper $formMapper,
		private RecordMapper $recordMapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return int[] register ids the user has favourited
	 */
	private function favorites(string $userId): array {
		/** @psalm-suppress DeprecatedMethod — IUserConfig replacement requires a newer Nextcloud; kept for the declared NC 30+ support range */
		$raw = $this->config->getUserValue($userId, 'dataforms', 'favorites', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_map('intval', $decoded) : [];
	}

	/**
	 * Toggle a register as a favourite for the user.
	 *
	 * @throws NotFoundException
	 */
	public function setFavorite(string $userId, int $id, bool $favorite): array {
		$register = $this->find($userId, $id); // read gate
		$favs = $this->favorites($userId);
		$favs = array_values(array_filter($favs, static fn ($f) => $f !== $id));
		if ($favorite) {
			$favs[] = $id;
		}
		/** @psalm-suppress DeprecatedMethod — see favorites(); kept for NC 30+ compatibility */
		$this->config->setUserValue($userId, 'dataforms', 'favorites', json_encode($favs));
		return $this->decorate($register, $userId, $this->groupIdsOf($userId));
	}

	/**
	 * Registers visible to the user, decorated with their permission flags.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(string $userId): array {
		$groupIds = $this->groupIdsOf($userId);
		$registers = $this->mapper->findAllForUser($userId, $groupIds);
		$favorites = $this->favorites($userId);
		$counts = $this->recordMapper->countsByRegisterIds(array_map(static fn (Register $r) => $r->getId(), $registers));
		$out = [];
		foreach ($registers as $register) {
			$out[] = $this->decorate($register, $userId, $groupIds, $favorites, $counts[$register->getId()] ?? 0);
		}
		return $out;
	}

	/**
	 * Read gate: returns the entity if the user may read it, else NotFound.
	 *
	 * @throws NotFoundException
	 */
	public function find(string $userId, int $id): Register {
		try {
			$register = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Register not found');
		}
		if (($this->permissionsFor($register, $userId) & Share::PERMISSION_READ) === 0) {
			throw new NotFoundException('Register not found');
		}
		return $register;
	}

	/**
	 * @return array<string,mixed>
	 * @throws NotFoundException
	 */
	public function findDecorated(string $userId, int $id): array {
		return $this->decorate($this->find($userId, $id), $userId, $this->groupIdsOf($userId));
	}

	public function create(string $userId, string $title, string $description = '', string $icon = '', string $color = ''): array {
		$now = $this->time->getTime();
		$register = new Register();
		$register->setTitle($title);
		$register->setDescription($description);
		$register->setIcon($icon);
		$register->setColor($color);
		$register->setOwner($userId);
		$register->setCreated($now);
		$register->setUpdated($now);
		$register = $this->mapper->insert($register);
		return $this->decorate($register, $userId, []);
	}

	/**
	 * Find a register the user may write records to (write bit).
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function findWritable(string $userId, int $id): Register {
		$register = $this->find($userId, $id);
		$this->require($register, $userId, Share::PERMISSION_WRITE, 'You do not have write access to this register');
		return $register;
	}

	/**
	 * Find a register the user may manage (schema, rules, sharing, deletion).
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function findManageable(string $userId, int $id): Register {
		$register = $this->find($userId, $id);
		$this->require($register, $userId, Share::PERMISSION_MANAGE, 'You do not have manage access to this register');
		return $register;
	}

	/**
	 * @param array<string,string> $changes title/description/icon/color
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function update(string $userId, int $id, array $changes): array {
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
		$register = $this->mapper->update($register);
		return $this->decorate($register, $userId, $this->groupIdsOf($userId));
	}

	/**
	 * Soft-delete the register and remove its shares.
	 *
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$register = $this->findManageable($userId, $id);
		$register->setDeletedAt($this->time->getTime());
		$this->mapper->update($register);
		$this->shareMapper->deleteByRegister($id);
		$this->viewMapper->deleteByRegister($id);
		$this->formMapper->deleteByRegister($id);
	}

	// ---- access control --------------------------------------------------

	/**
	 * Effective permission bitmask for a user on a register.
	 */
	public function permissionsFor(Register $register, string $userId): int {
		if ($register->getOwner() === $userId) {
			return Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE;
		}
		return $this->shareMapper->permissionsFor($register->getId(), $userId, $this->groupIdsOf($userId));
	}

	/**
	 * @throws ForbiddenException
	 */
	private function require(Register $register, string $userId, int $bit, string $message): void {
		if (($this->permissionsFor($register, $userId) & $bit) === 0) {
			throw new ForbiddenException($message);
		}
	}

	/**
	 * @param string[] $groupIds
	 * @return array<string,mixed>
	 */
	private function decorate(Register $register, string $userId, array $groupIds, ?array $favorites = null, ?int $recordCount = null): array {
		$perms = $register->getOwner() === $userId
			? (Share::PERMISSION_READ | Share::PERMISSION_WRITE | Share::PERMISSION_MANAGE)
			: $this->shareMapper->permissionsFor($register->getId(), $userId, $groupIds);
		$favorites ??= $this->favorites($userId);
		$recordCount ??= $this->recordMapper->countsByRegisterIds([$register->getId()])[$register->getId()] ?? 0;
		return array_merge($register->jsonSerialize(), [
			'isOwner' => $register->getOwner() === $userId,
			'permissions' => $perms,
			'canWrite' => (bool)($perms & Share::PERMISSION_WRITE),
			'canManage' => (bool)($perms & Share::PERMISSION_MANAGE),
			'favorite' => in_array($register->getId(), $favorites, true),
			'recordCount' => $recordCount,
		]);
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
