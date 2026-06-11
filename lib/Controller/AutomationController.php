<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\AutomationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class AutomationController extends OCSController {
	public function __construct(
		IRequest $request,
		private AutomationService $service,
		private AutomationLogService $logService,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	/**
	 * The action types managers may currently pick — those an admin has left
	 * enabled, with Talk/Deck hidden until the service account exists. Lets the
	 * builder show only usable actions. Global (not register-scoped).
	 */
	#[NoAdminRequired]
	public function actions(): DataResponse {
		return new DataResponse([
			'actions' => $this->service->availableActionTypes(),
			'serviceAccounts' => $this->service->serviceAccounts(),
		]);
	}

	#[NoAdminRequired]
	public function index(int $registerId): DataResponse {
		try {
			return new DataResponse($this->service->listForRegister($this->userId(), $registerId));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/** Recent automation runs for a register (manager-gated), newest first. */
	#[NoAdminRequired]
	public function log(int $registerId, int $limit = 100): DataResponse {
		try {
			return new DataResponse($this->logService->listForRegister($this->userId(), $registerId, $limit));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function create(int $registerId, string $name = '', string $trigger = '', string $actionType = '', mixed $condition = null, mixed $actionConfig = [], bool $enabled = true): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId(), $registerId, [
				'name' => $name, 'trigger' => $trigger, 'actionType' => $actionType,
				'condition' => $condition, 'actionConfig' => $actionConfig, 'enabled' => $enabled,
			]), Http::STATUS_CREATED);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function update(int $id, array $changes = []): DataResponse {
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
}
