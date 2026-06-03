<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FieldService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST surface for a register's fields (its schema), under
 * /ocs/v2.php/apps/dataforms/api/v1/registers/{registerId}/fields and
 * /ocs/v2.php/apps/dataforms/api/v1/fields/{id}.
 */
class FieldController extends OCSController {
	public function __construct(
		IRequest $request,
		private FieldService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	#[NoAdminRequired]
	public function index(int $registerId): DataResponse {
		try {
			return new DataResponse($this->service->listForRegister($this->userId(), $registerId));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @param mixed $config
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $label = '', string $type = '', string $machineName = '', $config = [], bool $mandatory = false, bool $unique = false, ?string $default = null): DataResponse {
		try {
			$field = $this->service->create($this->userId(), $registerId, [
				'label' => $label,
				'type' => $type,
				'machineName' => $machineName,
				'config' => $config,
				'mandatory' => $mandatory,
				'unique' => $unique,
				'default' => $default,
			]);
			return new DataResponse($field, Http::STATUS_CREATED);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @param mixed $config
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $label = null, $config = null, ?bool $mandatory = null, ?bool $unique = null, ?string $default = null): DataResponse {
		$changes = [];
		if ($label !== null) {
			$changes['label'] = $label;
		}
		if ($config !== null) {
			$changes['config'] = $config;
		}
		if ($mandatory !== null) {
			$changes['mandatory'] = $mandatory;
		}
		if ($unique !== null) {
			$changes['unique'] = $unique;
		}
		// 'default' is only applied when the key is explicitly present.
		if ($default !== null) {
			$changes['default'] = $default;
		}
		try {
			return new DataResponse($this->service->update($this->userId(), $id, $changes));
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($this->userId(), $id);
			return new DataResponse([]);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @param int[] $order field ids in the desired order
	 */
	#[NoAdminRequired]
	public function reorder(int $registerId, array $order = []): DataResponse {
		try {
			return new DataResponse($this->service->reorder($this->userId(), $registerId, $order));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
