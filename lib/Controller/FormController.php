<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FormService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class FormController extends OCSController {
	public function __construct(
		IRequest $request,
		private FormService $service,
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
	 * @param array<string,mixed> $definition
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $title = '', array $definition = []): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId(), $registerId, $title, $definition), Http::STATUS_CREATED);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @param array<string,mixed>|null $definition
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?array $definition = null): DataResponse {
		$changes = [];
		if ($title !== null) {
			$changes['title'] = $title;
		}
		if ($definition !== null) {
			$changes['definition'] = $definition;
		}
		try {
			return new DataResponse($this->service->update($this->userId(), $id, $changes));
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
