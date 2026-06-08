<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Workflow\NextcloudApiClient;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only management of the cross-app service account (used by the Talk/Deck
 * provisioning actions). Methods carry NO #[NoAdminRequired], so the framework
 * requires an admin; the OCS-APIRequest header provides CSRF protection. The
 * stored password is never returned.
 */
class ServiceAccountController extends OCSController {

	public function __construct(
		IRequest $request,
		private ServiceAccountService $account,
		private NextcloudApiClient $client,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function status(): DataResponse {
		$cred = $this->account->getCredentials();
		return new DataResponse([
			'configured' => $this->account->isConfigured(),
			'internalUrl' => $this->account->getInternalUrl(),
			'username' => $cred['username'] ?? '',
			'hasPassword' => $cred !== null,
		]);
	}

	public function save(string $internalUrl = '', string $username = '', string $password = ''): DataResponse {
		$this->account->save($internalUrl, $username, $password);
		return new DataResponse(['configured' => $this->account->isConfigured()]);
	}

	public function test(): DataResponse {
		return new DataResponse($this->client->test());
	}

	public function clear(): DataResponse {
		$this->account->clear();
		return new DataResponse(['configured' => false]);
	}
}
