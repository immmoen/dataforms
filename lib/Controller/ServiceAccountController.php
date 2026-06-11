<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Workflow\NextcloudApiClient;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only management of the cross-app service accounts (the default one plus
 * any named extras) used by the Talk/Deck provisioning actions. Methods carry NO
 * #[NoAdminRequired], so the framework requires an admin; the OCS-APIRequest
 * header provides CSRF protection. Stored passwords are never returned.
 */
class ServiceAccountController extends OCSController {

	public function __construct(
		IRequest $request,
		private ServiceAccountService $account,
		private NextcloudApiClient $client,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** All accounts (default + extras), without passwords. */
	public function index(): DataResponse {
		return new DataResponse(['accounts' => $this->account->adminList()]);
	}

	/**
	 * Create/update an account. id 'default' updates the default account; any other
	 * id updates that extra; an empty id creates a new extra. A blank password keeps
	 * the stored secret.
	 */
	public function save(string $id = '', string $name = '', string $internalUrl = '', string $username = '', string $password = ''): DataResponse {
		try {
			if ($id === ServiceAccountService::DEFAULT_ID) {
				$this->account->save($internalUrl, $username, $password);
			} else {
				$this->account->saveExtra($id, $name, $internalUrl, $username, $password);
			}
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return $this->index();
	}

	/** Connectivity/auth check for a given account. */
	public function test(string $id = ''): DataResponse {
		return new DataResponse($this->client->test($id));
	}

	/** Remove an account (the default is cleared; an extra is deleted). */
	public function remove(string $id = ''): DataResponse {
		$this->account->remove($id);
		return $this->index();
	}
}
