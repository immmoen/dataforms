<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ImportService;
use OCA\Dataforms\Service\RecordService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * REST surface for records, under
 * /ocs/v2.php/apps/dataforms/api/v1/registers/{registerId}/records and
 * /ocs/v2.php/apps/dataforms/api/v1/records/{id}.
 */
class RecordController extends OCSController {
	public function __construct(
		IRequest $request,
		private RecordService $service,
		private ImportService $importService,
		private IUserSession $userSession,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : '';
	}

	/**
	 * Resolve the user, then release the PHP session lock so concurrent read
	 * requests from the SPA don't serialise behind each other (or behind other
	 * apps' long-polling). Safe for read-only actions that never write session.
	 */
	private function readUserId(): string {
		$userId = $this->userId();
		$this->session->close();
		return $userId;
	}

	#[NoAdminRequired]
	public function index(int $registerId, int $limit = 50, int $offset = 0, string $sort = 'updated', string $direction = 'DESC', string $search = '', string $filter = ''): DataResponse {
		$filters = [];
		if ($filter !== '') {
			$decoded = json_decode($filter, true);
			if (is_array($decoded)) {
				$filters = $decoded;
			}
		}
		try {
			return new DataResponse($this->service->list($this->readUserId(), $registerId, $limit, $offset, $sort, $direction, $search, $filters));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Pickable options for a relation target register (id + display label).
	 */
	#[NoAdminRequired]
	public function options(int $registerId, string $display = '', string $search = ''): DataResponse {
		try {
			return new DataResponse($this->service->options($this->readUserId(), $registerId, $display, $search));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->service->get($this->readUserId(), $id));
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	public function create(int $registerId, array $values = []): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId(), $registerId, $values), Http::STATUS_CREATED);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	public function update(int $id, array $values = []): DataResponse {
		try {
			return new DataResponse($this->service->update($this->userId(), $id, $values));
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	public function import(int $registerId, string $csv = ''): DataResponse {
		try {
			return new DataResponse($this->importService->importCsv($this->userId(), $registerId, $csv));
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
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
