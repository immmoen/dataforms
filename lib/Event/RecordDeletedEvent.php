<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Event;

/**
 * Dispatched after a record is (soft-)deleted.
 *
 * @psalm-immutable
 */
class RecordDeletedEvent extends ARecordEvent {
}
