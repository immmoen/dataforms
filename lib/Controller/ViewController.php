<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ViewController extends OCSController {
	use HandlesApiExceptions;

	public function __construct(
		IRequest $request,
		private ViewService $service,
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

	/**
	 * @param array<string,mixed> $definition
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $title = '', array $definition = [], bool $shared = false): DataResponse {
		return $this->handle(fn () => $this->service->create($this->userId(), $registerId, $title, $definition, $shared), Http::STATUS_CREATED);
	}

	/**
	 * @param array<string,mixed>|null $definition
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?array $definition = null, ?bool $shared = null): DataResponse {
		$changes = [];
		if ($title !== null) {
			$changes['title'] = $title;
		}
		if ($definition !== null) {
			$changes['definition'] = $definition;
		}
		if ($shared !== null) {
			$changes['shared'] = $shared;
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
}
