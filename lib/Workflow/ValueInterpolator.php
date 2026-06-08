<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

/**
 * Shared {machineName} placeholder substitution for workflow actions, with an
 * optional date-format suffix:
 *
 *   {client}            → the field value
 *   {meeting_date|Y}    → 2026
 *   {meeting_date|Ymd}  → 20260701
 *   {meeting_date|d-m-Y}→ 01-07-2026
 *
 * A format is applied only when the value is a date ("YYYY-MM-DD") or datetime
 * ("YYYY-MM-DDTHH:MM"); otherwise the raw value is used. The format string is
 * whitelisted to PHP date() tokens + simple separators so a template can't smuggle
 * arbitrary output in. Callers may pass a $valueTransform to post-process each
 * substituted value (e.g. the folder action strips path separators).
 */
class ValueInterpolator {

	/** Allowed characters in a date format suffix (PHP date() tokens + separators). */
	private const FORMAT_WHITELIST = '/^[YyMmDdjnHGhisAaNlwFt0-9 ._:\/-]+$/';

	/**
	 * @param array<string,mixed> $values
	 * @param null|callable(string):string $valueTransform
	 */
	public function interpolate(string $template, array $values, ?callable $valueTransform = null): string {
		// Field name, optionally a relation sub-field path (subgroup.code), and an
		// optional |date-format suffix.
		return (string)preg_replace_callback(
			'/\{([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)(?:\|([^}|]+))?\}/i',
			function (array $m) use ($values, $valueTransform): string {
				$raw = $values[$m[1]] ?? '';
				$format = isset($m[2]) ? trim($m[2]) : '';
				$formatted = $format !== '' ? $this->formatDate($raw, $format) : null;
				$out = $formatted ?? $this->stringify($raw);
				return $valueTransform !== null ? $valueTransform($out) : $out;
			},
			$template
		);
	}

	/**
	 * Reformat a date/datetime string with a whitelisted PHP date() format, or null
	 * if the value isn't a date or the format isn't allowed.
	 *
	 * @param mixed $raw
	 */
	private function formatDate($raw, string $format): ?string {
		if (!is_string($raw) || $raw === '' || !preg_match(self::FORMAT_WHITELIST, $format)) {
			return null;
		}
		try {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
				$dt = new \DateTimeImmutable($raw . ' 00:00:00');
			} elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $raw)) {
				$dt = new \DateTimeImmutable($raw);
			} else {
				return null;
			}
		} catch (\Throwable) {
			return null;
		}
		return $dt->format($format);
	}

	/**
	 * @param mixed $v
	 */
	private function stringify($v): string {
		if (is_bool($v)) {
			return $v ? 'yes' : 'no';
		}
		if (is_array($v)) {
			$parts = [];
			foreach ($v as $x) {
				$parts[] = is_array($x) ? (string)($x['label'] ?? $x['id'] ?? '') : (string)$x;
			}
			return implode(', ', $parts);
		}
		return (string)$v;
	}
}
