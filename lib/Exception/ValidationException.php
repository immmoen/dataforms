<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Exception;

/**
 * Thrown when client-supplied data fails server-side validation.
 */
class ValidationException extends \RuntimeException {
}
