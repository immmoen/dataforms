<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Event;

use OCP\EventDispatcher\Event;

/**
 * Base class for the app's typed record events. The (future) automation engine
 * and any third-party app can subscribe to these to react to record changes —
 * the foundation of the workflow primitive (Trigger → Action). Emitting events
 * keeps automation decoupled from the write path.
 *
 * @psalm-immutable
 */
abstract class ARecordEvent extends Event {
	/**
	 * @param array<string,mixed> $values resolved record values (machineName => value)
	 * @param array<string,mixed> $changed for updates: changed field labels; empty otherwise
	 */
	public function __construct(
		private int $registerId,
		private int $recordId,
		private string $userId,
		private array $values = [],
		private array $changed = [],
	) {
		parent::__construct();
	}

	public function getRegisterId(): int {
		return $this->registerId;
	}

	public function getRecordId(): int {
		return $this->recordId;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	/** @return array<string,mixed> */
	public function getValues(): array {
		return $this->values;
	}

	/** @return array<string,mixed> */
	public function getChangedFields(): array {
		return $this->changed;
	}
}
