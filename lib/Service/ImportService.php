<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * CSV import. Columns are matched to fields by header (label or machine name).
 * Each row is validated through RecordService (same validation, type coercion
 * and computed-field evaluation as a single create), but written via
 * createForImport(): the whole file is one DB transaction and — by design — a
 * bulk load does NOT fire per-row automations (no webhook/email/notification
 * storm). Computed, relation and file fields are skipped on import.
 */
class ImportService {
	/**
	 * Hard cap on rows processed in a single import request, so one upload can't
	 * pin a PHP worker past max_execution_time. Larger files must be split.
	 */
	private const MAX_ROWS = 5000;

	public function __construct(
		private RegisterService $registerService,
		private FieldMapper $fieldMapper,
		private RecordService $recordService,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{imported:int,failed:int,errors:array<int,string>}
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function importCsv(string $userId, int $registerId, string $csv): array {
		$this->registerService->findWritable($userId, $registerId);
		$fields = $this->fieldMapper->findByRegister($registerId);

		$handle = fopen('php://temp', 'r+');
		fwrite($handle, $csv);
		rewind($handle);

		$header = fgetcsv($handle);
		if ($header === false || $header === null) {
			fclose($handle);
			throw new ValidationException('The CSV file is empty');
		}
		// Strip a leading UTF-8 BOM from the first header cell.
		$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

		$columnMap = $this->mapColumns($header, $fields);
		if (count($columnMap) === 0) {
			fclose($handle);
			throw new ValidationException('No CSV columns matched this register\'s fields');
		}

		$imported = 0;
		$failed = 0;
		$errors = [];
		$line = 1;
		$capped = false;

		// One transaction for the whole file: a per-row validation failure is
		// caught and skipped (it never writes, so the transaction stays clean),
		// while any catastrophic DB error rolls the entire batch back rather than
		// leaving a half-imported register.
		$this->db->beginTransaction();
		try {
			while (($row = fgetcsv($handle)) !== false) {
				$line++;
				if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
					continue; // blank line
				}
				if (($imported + $failed) >= self::MAX_ROWS) {
					$capped = true;
					break;
				}
				$values = [];
				foreach ($columnMap as $index => $field) {
					if (array_key_exists($index, $row)) {
						$values[$field->getMachineName()] = $this->coerce($field, (string)$row[$index]);
					}
				}
				try {
					$this->recordService->createForImport($userId, $registerId, $fields, $values);
					$imported++;
				} catch (ValidationException $e) {
					$failed++;
					if (count($errors) < 20) {
						$errors[] = 'Row ' . $line . ': ' . $e->getMessage();
					}
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			fclose($handle);
			throw $e;
		}
		fclose($handle);

		if ($capped) {
			$errors[] = 'Import stopped at the ' . self::MAX_ROWS . '-row limit. Split the file and import the remaining rows in a second batch.';
		}

		return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
	}

	/**
	 * @param string[] $header
	 * @param Field[] $fields
	 * @return array<int,Field> column index => field
	 */
	private function mapColumns(array $header, array $fields): array {
		$byLabel = [];
		$byMachine = [];
		foreach ($fields as $field) {
			// Skip fields that can't be sensibly imported from a CSV cell.
			if (in_array($field->getType(), ['relation', 'file'], true)) {
				continue;
			}
			$byLabel[mb_strtolower(trim($field->getLabel()))] = $field;
			$byMachine[mb_strtolower(trim($field->getMachineName()))] = $field;
		}
		$map = [];
		foreach ($header as $index => $name) {
			$key = mb_strtolower(trim((string)$name));
			if (isset($byLabel[$key])) {
				$map[$index] = $byLabel[$key];
			} elseif (isset($byMachine[$key])) {
				$map[$index] = $byMachine[$key];
			}
		}
		return $map;
	}

	/**
	 * Light coercion from a CSV cell to a logical value; RecordService does the
	 * authoritative validation.
	 *
	 * @return mixed
	 */
	private function coerce(Field $field, string $cell) {
		$cell = trim($cell);
		if ($cell === '') {
			return null;
		}
		return match ($field->getType()) {
			'boolean' => in_array(mb_strtolower($cell), ['1', 'true', 'yes', 'y', 'on'], true),
			'number', 'currency', 'percentage' => is_numeric($cell) ? (float)$cell : $cell,
			'multiselect' => array_values(array_filter(array_map('trim', explode(',', $cell)), static fn ($s) => $s !== '')),
			default => $cell,
		};
	}
}
