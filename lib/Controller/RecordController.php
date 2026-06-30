<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
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
	use HandlesApiExceptions;

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
		return $this->handle(fn () => $this->service->list($this->readUserId(), $registerId, $limit, $offset, $sort, $direction, $search, $filters));
	}

	/**
	 * Pickable options for a relation target register (id + display label).
	 */
	#[NoAdminRequired]
	public function options(int $registerId, string $display = '', string $search = ''): DataResponse {
		return $this->handle(fn () => $this->service->options($this->readUserId(), $registerId, $display, $search));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->handle(fn () => $this->service->get($this->readUserId(), $id));
	}

	/**
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	public function create(int $registerId, array $values = []): DataResponse {
		return $this->handle(fn () => $this->service->create($this->userId(), $registerId, $values), Http::STATUS_CREATED);
	}

	/**
	 * @param array<string,mixed> $values
	 */
	#[NoAdminRequired]
	public function update(int $id, array $values = []): DataResponse {
		return $this->handle(fn () => $this->service->update($this->userId(), $id, $values));
	}

	#[NoAdminRequired]
	public function import(int $registerId, string $csv = ''): DataResponse {
		return $this->handle(fn () => $this->importService->importCsv($this->userId(), $registerId, $csv));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		// A 'block' delete policy surfaces as a 422 (the record exists but an
		// integrity rule forbids removing it), distinct from a 400 input error.
		return $this->handle(function () use ($id): array {
			$this->service->delete($this->userId(), $id);
			return [];
		}, Http::STATUS_OK, Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	#[NoAdminRequired]
	public function history(int $id): DataResponse {
		return $this->handle(fn () => $this->service->history($this->readUserId(), $id));
	}
}
