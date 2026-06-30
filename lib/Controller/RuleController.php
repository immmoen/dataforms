<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\RuleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

class RuleController extends OCSController {
	use HandlesApiExceptions;

	public function __construct(
		IRequest $request,
		private RuleService $service,
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
	 * @param mixed $conditions
	 * @param mixed $value
	 * @param mixed $validation
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $effect = '', string $target = '', $conditions = null, $value = null, ?string $expression = null, $validation = null, bool $enabled = true): DataResponse {
		// compact() resolves in this scope; an arrow fn would not capture the
		// names (it only auto-captures variables it lexically references).
		$data = compact('effect', 'target', 'conditions', 'value', 'expression', 'validation', 'enabled');
		return $this->handle(fn () => $this->service->create($this->userId(), $registerId, $data), Http::STATUS_CREATED);
	}

	/**
	 * @param mixed $conditions
	 * @param mixed $value
	 * @param mixed $validation
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $effect = null, ?string $target = null, $conditions = null, $value = null, ?string $expression = null, $validation = null, ?bool $enabled = null): DataResponse {
		$data = array_filter([
			'effect' => $effect,
			'target' => $target,
			'conditions' => $conditions,
			'value' => $value,
			'expression' => $expression,
			'validation' => $validation,
			'enabled' => $enabled,
		], static fn ($v) => $v !== null);
		return $this->handle(fn () => $this->service->update($this->userId(), $id, $data));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->handle(function () use ($id): array {
			$this->service->delete($this->userId(), $id);
			return [];
		});
	}
}
