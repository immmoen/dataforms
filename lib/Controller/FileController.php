<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

/**
 * Resolves a file chosen in the Nextcloud file picker (a path) to its file id
 * and name, so file-attachment fields can store the id (never a blob).
 */
class FileController extends OCSController {
	public function __construct(
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function resolve(string $path): DataResponse {
		$user = $this->userSession->getUser();
		$userId = $user !== null ? $user->getUID() : '';
		$this->session->close();

		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			return new DataResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse([
			'id' => $node->getId(),
			'name' => $node->getName(),
		]);
	}
}
