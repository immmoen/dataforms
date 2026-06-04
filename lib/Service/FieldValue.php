<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

/**
 * Maps a field's logical value to/from the typed EAV columns in
 * df_record_values. Exactly one column is populated per (record, field).
 *
 * Column choice by type:
 *   value_number   -> number, currency, percentage
 *   value_bool     -> boolean
 *   value_datetime -> date, datetime (epoch seconds)
 *   value_string   -> everything else (text, longtext, email, url, phone,
 *                     select, user, group, time "HH:MM"); multiselect is a
 *                     JSON array string.
 */
class FieldValue {
	public static function column(string $type): string {
		return match ($type) {
			'number', 'currency', 'percentage' => 'value_number',
			'boolean' => 'value_bool',
			'date', 'datetime' => 'value_datetime',
			'relation' => 'value_ref_record_id',
			'file' => 'value_file_id',
			default => 'value_string',
		};
	}

	/**
	 * Coerce an incoming value into the storage payload for its typed column.
	 *
	 * @param mixed $value
	 * @return array{column:string,value:mixed} or column '' to skip (empty).
	 */
	public static function toStorage(string $type, $value): array {
		// Auto fields derive from record metadata; never stored as values.
		if ($type === 'auto') {
			return ['column' => '', 'value' => null];
		}
		$column = self::column($type);
		if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
			return ['column' => '', 'value' => null];
		}
		$stored = match ($type) {
			'number', 'currency', 'percentage' => (float)$value,
			'boolean' => self::asBool($value) ? 1 : 0,
			'date', 'datetime' => self::toEpoch((string)$value),
			'relation', 'file' => (int)(is_array($value) ? ($value['id'] ?? 0) : $value),
			'multiselect' => json_encode(array_values((array)$value), JSON_THROW_ON_ERROR),
			default => (string)$value,
		};
		return ['column' => $column, 'value' => $stored];
	}

	/**
	 * Rebuild the logical value from a df_record_values DB row.
	 *
	 * @param array<string,mixed> $row
	 * @return mixed
	 */
	public static function fromStorage(string $type, array $row) {
		switch ($type) {
			case 'number':
			case 'currency':
			case 'percentage':
				return $row['value_number'] === null ? null : (float)$row['value_number'];
			case 'boolean':
				return $row['value_bool'] === null ? null : (bool)$row['value_bool'];
			case 'date':
				return $row['value_datetime'] === null ? null : gmdate('Y-m-d', (int)$row['value_datetime']);
			case 'datetime':
				return $row['value_datetime'] === null ? null : gmdate('Y-m-d\TH:i', (int)$row['value_datetime']);
			case 'relation':
				// Returns the raw target id; RecordService resolves the label.
				return $row['value_ref_record_id'] === null ? null : (int)$row['value_ref_record_id'];
			case 'file':
				// Returns the raw file id; RecordService resolves name + link.
				return $row['value_file_id'] === null ? null : (int)$row['value_file_id'];
			case 'multiselect':
				$raw = $row['value_string'];
				if ($raw === null || $raw === '') {
					return [];
				}
				$decoded = json_decode((string)$raw, true);
				return is_array($decoded) ? $decoded : [];
			default:
				return $row['value_string'];
		}
	}

	/**
	 * @param mixed $value
	 */
	private static function asBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
	}

	private static function toEpoch(string $value): int {
		$ts = strtotime($value);
		return $ts === false ? 0 : $ts;
	}
}
