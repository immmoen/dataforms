<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Uploads a file from the user's computer into their Nextcloud Files (under a
 * "Dataforms" folder) and returns its id + name, so file-attachment fields stay
 * referenced by file id (never stored as a blob in the app DB). A normal route
 * (CSRF-protected) so multipart form data is handled cleanly.
 */
class UploadController extends Controller {
	private const FOLDER = 'Dataforms';

	public function __construct(
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function upload(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}
		$file = $this->request->getUploadedFile('file');
		if (empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
			return new JSONResponse(['message' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		try {
			$folder = $userFolder->nodeExists(self::FOLDER)
				? $userFolder->get(self::FOLDER)
				: $userFolder->newFolder(self::FOLDER);
			if (!$folder instanceof Folder) {
				return new JSONResponse(['message' => 'Upload location is not a folder'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$name = $folder->getNonExistingName(basename((string)$file['name']));
			$node = $folder->newFile($name, file_get_contents($file['tmp_name']));
		} catch (NotPermittedException) {
			return new JSONResponse(['message' => 'Could not save the file'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $node->getId(), 'name' => $node->getName()]);
	}
}
