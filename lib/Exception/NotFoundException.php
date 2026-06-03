<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Exception;

/**
 * Thrown when a requested entity does not exist or is not visible to the user.
 */
class NotFoundException extends \RuntimeException {
}
