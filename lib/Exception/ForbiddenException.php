<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Exception;

/**
 * Thrown when the current user lacks permission for an operation.
 */
class ForbiddenException extends \RuntimeException {
}
