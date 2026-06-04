<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Share;
use OCA\Dataforms\Db\View;
use OCA\Dataforms\Db\ViewMapper;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Saved views. Anyone with read access to a register can save private views;
 * a view marked "shared" is visible to everyone who can see the register. A
 * view is editable by its owner or a register manager.
 */
class ViewService {
	public function __construct(
		private ViewMapper $mapper,
		private RegisterService $registerService,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 * @throws NotFoundException
	 */
	public function listForRegister(string $userId, int $registerId): array {
		$this->registerService->find($userId, $registerId); // read gate
		return array_map(
			fn (View $v) => $this->decorate($v, $userId),
			$this->mapper->findForRegister($registerId, $userId)
		);
	}

	/**
	 * @param array<string,mixed> $definition
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, string $title, array $definition, bool $shared): array {
		$this->registerService->find($userId, $registerId);
		$title = trim($title);
		if ($title === '') {
			throw new ValidationException('A view needs a title');
		}
		$view = new View();
		$view->setRegisterId($registerId);
		$view->setTitle($title);
		$view->setOwner($userId);
		$view->setShared($shared);
		$view->setDefinition($this->encode($definition));
		$now = $this->time->getTime();
		$view->setCreated($now);
		$view->setUpdated($now);
		return $this->decorate($this->mapper->insert($view), $userId);
	}

	/**
	 * @param array<string,mixed> $changes
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function update(string $userId, int $id, array $changes): array {
		$view = $this->findEditable($userId, $id);
		if (isset($changes['title']) && trim((string)$changes['title']) !== '') {
			$view->setTitle(trim((string)$changes['title']));
		}
		if (array_key_exists('shared', $changes)) {
			$view->setShared((bool)$changes['shared']);
		}
		if (array_key_exists('definition', $changes) && is_array($changes['definition'])) {
			$view->setDefinition($this->encode($changes['definition']));
		}
		$view->setUpdated($this->time->getTime());
		return $this->decorate($this->mapper->update($view), $userId);
	}

	/**
	 * @throws NotFoundException
	 * @throws ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$view = $this->findEditable($userId, $id);
		$this->mapper->delete($view);
	}

	private function findEditable(string $userId, int $id): View {
		try {
			$view = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('View not found');
		}
		if ($view->getOwner() === $userId) {
			return $view;
		}
		// A register manager may also tidy up shared views.
		$register = $this->registerService->find($userId, $view->getRegisterId());
		if (($this->registerService->permissionsFor($register, $userId) & Share::PERMISSION_MANAGE) !== 0) {
			return $view;
		}
		throw new ForbiddenException('Only the view owner or a register manager can change this view');
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	private function encode(array $definition): string {
		return json_encode([
			'columns' => array_values((array)($definition['columns'] ?? [])),
			'filters' => array_values((array)($definition['filters'] ?? [])),
			'sort' => (string)($definition['sort'] ?? 'updated'),
			'direction' => strtoupper((string)($definition['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
			'search' => (string)($definition['search'] ?? ''),
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decorate(View $view, string $userId): array {
		return array_merge($view->jsonSerialize(), ['isOwner' => $view->getOwner() === $userId]);
	}
}
