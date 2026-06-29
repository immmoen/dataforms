<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\FieldService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * REST surface for a register's fields (its schema), under
 * /ocs/v2.php/apps/dataforms/api/v1/registers/{registerId}/fields and
 * /ocs/v2.php/apps/dataforms/api/v1/fields/{id}.
 */
class FieldController extends OCSController {
	use HandlesApiExceptions;

	public function __construct(
		IRequest $request,
		private FieldService $service,
		private IUserSession $userSession,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	#[NoAdminRequired]
	public function index(int $registerId): DataResponse {
		return $this->handle(function () use ($registerId) {
			$userId = $this->userId();
			$this->session->close(); // release the session lock for this read
			return $this->service->listForRegister($userId, $registerId);
		});
	}

	/**
	 * @param mixed $config
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $label = '', string $type = '', string $machineName = '', $config = [], bool $mandatory = false, bool $unique = false, ?string $default = null): DataResponse {
		return $this->handle(fn () => $this->service->create($this->userId(), $registerId, [
			'label' => $label,
			'type' => $type,
			'machineName' => $machineName,
			'config' => $config,
			'mandatory' => $mandatory,
			'unique' => $unique,
			'default' => $default,
		]), Http::STATUS_CREATED);
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
		return $this->handle(fn () => $this->service->update($this->userId(), $id, $changes));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$this->service->delete($this->userId(), $id);
			return [];
		});
	}

	/**
	 * @param int[] $order field ids in the desired order
	 */
	#[NoAdminRequired]
	public function reorder(int $registerId, array $order = []): DataResponse {
		return $this->handle(fn () => $this->service->reorder($this->userId(), $registerId, $order));
	}
}
