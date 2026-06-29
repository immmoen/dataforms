<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
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
	use HandlesApiExceptions;

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
		return $this->handle(fn () => $this->service->findDecorated($this->userId(), $id));
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
		return $this->handle(fn () => $this->service->update($this->userId(), $id, $changes));
	}

	#[NoAdminRequired]
	public function favorite(int $id, bool $favorite = true): DataResponse {
		return $this->handle(fn () => $this->service->setFavorite($this->userId(), $id, $favorite));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$this->service->delete($this->userId(), $id);
			return [];
		});
	}
}
