<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Exception;

/**
 * Thrown when client-supplied data fails server-side validation. May carry a
 * map of per-field error messages (machineName => message).
 */
class ValidationException extends \RuntimeException {
	/**
	 * @param array<string,string> $errors
	 */
	public function __construct(string $message, private array $errors = []) {
		parent::__construct($message);
	}

	/**
	 * @return array<string,string>
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
