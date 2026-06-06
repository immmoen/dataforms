<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RecordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * CSV export of a register's records. A normal (non-OCS) route so it can return
 * a file download. UTF-8 with a BOM so Excel detects the encoding.
 */
class ExportController extends Controller {
	private const EXPORT_CAP = 500;

	public function __construct(
		IRequest $request,
		private RecordService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function csv(int $registerId): DataDownloadResponse|DataResponse {
		$user = $this->userSession->getUser();
		$userId = $user !== null ? $user->getUID() : '';

		try {
			$data = $this->service->list($userId, $registerId, self::EXPORT_CAP, 0, 'updated', 'DESC', '');
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$fields = $data['fields'];
		$handle = fopen('php://temp', 'r+');

		$header = ['id'];
		foreach ($fields as $field) {
			$header[] = $this->csvSafe((string)$field['label']);
		}
		fputcsv($handle, $header);

		foreach ($data['records'] as $record) {
			$row = [$record['id']];
			foreach ($fields as $field) {
				$value = $record['values'][$field['machineName']] ?? '';
				if (is_array($value)) {
					$value = implode(', ', $value);
				} elseif (is_bool($value)) {
					$value = $value ? 'yes' : 'no';
				}
				$row[] = $this->csvSafe((string)$value);
			}
			fputcsv($handle, $row);
		}

		rewind($handle);
		$csv = "\xEF\xBB\xBF" . (string)stream_get_contents($handle); // UTF-8 BOM
		fclose($handle);

		$filename = 'register-' . $registerId . '.csv';
		return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
	}

	/**
	 * Neutralise spreadsheet formula injection. A cell beginning with =, +, -, @
	 * or a control char (tab/CR/LF) is evaluated as a formula by Excel/Sheets, so
	 * a stored value like "=HYPERLINK(...)" or "=cmd|'...'!A1" can execute when the
	 * export is opened. Such cells are prefixed with a single quote so they are
	 * treated as literal text. Genuine numbers (including negatives/decimals) are
	 * left untouched.
	 */
	private function csvSafe(string $value): string {
		if ($value === '') {
			return $value;
		}
		$first = $value[0];
		if ($first === "\t" || $first === "\r" || $first === "\n") {
			return "'" . $value;
		}
		if (in_array($first, ['=', '+', '-', '@'], true) && !is_numeric($value)) {
			return "'" . $value;
		}
		return $value;
	}
}
