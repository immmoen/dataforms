<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\RuleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class RuleController extends OCSController {
	public function __construct(
		IRequest $request,
		private RuleService $service,
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
	 * @param mixed $conditions
	 * @param mixed $value
	 * @param mixed $validation
	 */
	#[NoAdminRequired]
	public function create(int $registerId, string $effect = '', string $target = '', $conditions = null, $value = null, ?string $expression = null, $validation = null, bool $enabled = true): DataResponse {
		try {
			$rule = $this->service->create($this->userId(), $registerId, compact('effect', 'target', 'conditions', 'value', 'expression', 'validation', 'enabled'));
			return new DataResponse($rule, Http::STATUS_CREATED);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
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
		try {
			return new DataResponse($this->service->update($this->userId(), $id, $data));
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
