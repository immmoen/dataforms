<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Rules;

/**
 * Thrown when a computed-field / validation expression is malformed or uses a
 * disallowed construct.
 */
class ExpressionException extends \RuntimeException {
}
