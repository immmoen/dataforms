<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

/**
 * What an action gets to work with when an automation fires.
 */
class ActionContext {
	/**
	 * @param array<string,mixed> $values resolved record values
	 * @param array<string,mixed> $config the automation's action_config
	 */
	public function __construct(
		public readonly int $registerId,
		public readonly int $recordId,
		public readonly string $userId,
		public readonly string $automationName,
		public readonly array $values,
		public readonly array $config,
	) {
	}
}
