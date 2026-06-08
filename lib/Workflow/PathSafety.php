<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

/**
 * Shared path-sanitisation for the file-touching workflow actions
 * (provision_folders, apply_template). Keeps a field value from escaping its
 * folder segment and bounds path depth — the security boundary these actions
 * rely on. Static + pure so it is trivially testable and shared without DI.
 */
final class PathSafety {

	/** Maximum number of path segments created/traversed for one template. */
	public const MAX_DEPTH = 10;

	/** Windows reserved device names, refused as folder names. */
	private const RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i';

	/** Zero-width and bidirectional control characters (spoofing / confusables). */
	private const BIDI = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

	/**
	 * Split a relative path into safe segments: each trimmed, bidi/illegal/reserved
	 * names dropped, "." / ".." impossible, depth capped — so the result can only
	 * ever descend a bounded distance within a user's folder.
	 *
	 * @return string[]
	 */
	public static function safeSegments(string $path, int $maxDepth = self::MAX_DEPTH): array {
		$out = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $raw) {
			$seg = trim($raw);
			$seg = (string)preg_replace(self::BIDI, '', $seg);
			$seg = trim($seg, '.'); // forbid leading/trailing dots → blocks "." / ".."
			$seg = (string)preg_replace('#[\\\\/<>:"|?*\x00-\x1F]#', '', $seg);
			$seg = trim($seg);
			if ($seg === '' || preg_match(self::RESERVED, $seg)) {
				continue;
			}
			$out[] = mb_substr($seg, 0, 250);
			if (count($out) >= $maxDepth) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Strip path separators and control/bidi characters from an interpolated value
	 * so it stays within a single path segment (used as the interpolation
	 * value-transform; safeSegments() is still the authoritative guard).
	 */
	public static function pathSafeValue(string $s): string {
		$s = str_replace(['/', '\\', "\0"], ' ', $s);
		$s = (string)preg_replace(self::BIDI, '', $s);
		$s = (string)preg_replace('/[\x00-\x1F]/', '', $s);
		return trim($s);
	}
}
