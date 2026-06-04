<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST surface for registers, under
 * /ocs/v2.php/apps/dataforms/api/v1/registers.
 *
 * Every action resolves the current user server-side and delegates all access
 * decisions to RegisterService — the client never supplies ownership.
 */
class RegisterController extends OCSController {
	public function __construct(
		IRequest $request,
		private RegisterService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->findAll($this->userId()));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->service->findDecorated($this->userId(), $id));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function create(string $title, string $description = '', string $icon = '', string $color = ''): DataResponse {
		$title = trim($title);
		if ($title === '') {
			return new DataResponse(['message' => 'Title is required'], Http::STATUS_BAD_REQUEST);
		}
		$register = $this->service->create($this->userId(), $title, $description, $icon, $color);
		return new DataResponse($register, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $description = null, ?string $icon = null, ?string $color = null): DataResponse {
		$changes = [];
		foreach (['title' => $title, 'description' => $description, 'icon' => $icon, 'color' => $color] as $key => $value) {
			if ($value !== null) {
				$changes[$key] = $value;
			}
		}
		if (isset($changes['title']) && trim($changes['title']) === '') {
			return new DataResponse(['message' => 'Title cannot be empty'], Http::STATUS_BAD_REQUEST);
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
	public function favorite(int $id, bool $favorite = true): DataResponse {
		try {
			return new DataResponse($this->service->setFavorite($this->userId(), $id, $favorite));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($this->userId(), $id);
			return new DataResponse([], Http::STATUS_OK);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
