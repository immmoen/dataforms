<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Form;
use OCA\Dataforms\Db\FormMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Db\FieldMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Data-entry forms. Any user who can read a register may list its forms (to
 * enter records); only a register manager may create/edit/delete forms.
 */
class FormService {
	public function __construct(
		private FormMapper $mapper,
		private FieldMapper $fieldMapper,
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
		return array_map(static fn (Form $f) => $f->jsonSerialize(), $this->mapper->findByRegister($registerId));
	}

	/**
	 * @param array<string,mixed> $definition
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function create(string $userId, int $registerId, string $title, array $definition): array {
		$this->registerService->findManageable($userId, $registerId);
		$title = trim($title);
		if ($title === '') {
			throw new ValidationException('A form needs a title');
		}
		$form = new Form();
		$form->setRegisterId($registerId);
		$form->setTitle($title);
		$form->setDefinition($this->encode($registerId, $definition));
		$form->setPosition($this->nextPosition($registerId));
		$now = $this->time->getTime();
		$form->setCreated($now);
		$form->setUpdated($now);
		return $this->mapper->insert($form)->jsonSerialize();
	}

	/**
	 * @param array<string,mixed> $changes
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function update(string $userId, int $id, array $changes): array {
		$form = $this->findManageableForm($userId, $id);
		if (isset($changes['title']) && trim((string)$changes['title']) !== '') {
			$form->setTitle(trim((string)$changes['title']));
		}
		if (array_key_exists('definition', $changes) && is_array($changes['definition'])) {
			$form->setDefinition($this->encode($form->getRegisterId(), $changes['definition']));
		}
		$form->setUpdated($this->time->getTime());
		return $this->mapper->update($form)->jsonSerialize();
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function delete(string $userId, int $id): void {
		$form = $this->findManageableForm($userId, $id);
		$this->mapper->delete($form);
	}

	private function findManageableForm(string $userId, int $id): Form {
		try {
			$form = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Form not found');
		}
		$this->registerService->findManageable($userId, $form->getRegisterId());
		return $form;
	}

	private function nextPosition(int $registerId): int {
		$forms = $this->mapper->findByRegister($registerId);
		return count($forms);
	}

	/**
	 * Validate sections and keep only machine names that exist in the register.
	 *
	 * @param array<string,mixed> $definition
	 */
	private function encode(int $registerId, array $definition): string {
		$valid = [];
		foreach ($this->fieldMapper->findByRegister($registerId) as $field) {
			$valid[$field->getMachineName()] = true;
		}
		$sections = [];
		foreach (($definition['sections'] ?? []) as $section) {
			$fields = [];
			foreach ((array)($section['fields'] ?? []) as $machineName) {
				$machineName = (string)$machineName;
				if (isset($valid[$machineName])) {
					$fields[] = $machineName;
				}
			}
			$sections[] = [
				'title' => trim((string)($section['title'] ?? '')),
				'fields' => $fields,
			];
		}
		return json_encode(['sections' => $sections], JSON_THROW_ON_ERROR);
	}
}
