<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ShareController extends OCSController {
	use HandlesApiExceptions;

	public function __construct(
		IRequest $request,
		private ShareService $service,
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
		return $this->handle(fn () => $this->service->listForRegister($this->userId(), $registerId));
	}

	#[NoAdminRequired]
	public function sharees(int $registerId, string $search = ''): DataResponse {
		return $this->handle(fn () => $this->service->searchSharees($this->userId(), $registerId, $search));
	}

	#[NoAdminRequired]
	public function create(int $registerId, string $shareType = 'user', string $shareWith = '', int $permissions = 1): DataResponse {
		return $this->handle(fn () => $this->service->add($this->userId(), $registerId, $shareType, $shareWith, $permissions), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(int $id, int $permissions): DataResponse {
		return $this->handle(fn () => $this->service->setPermissions($this->userId(), $id, $permissions));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$this->service->remove($this->userId(), $id);
			return [];
		});
	}
}
