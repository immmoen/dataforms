<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

/**
 * A workflow action. New actions are new classes registered in ActionRegistry —
 * config over code, no switch statements threaded through the engine.
 */
interface IAction {
	/** The action_type this handles (e.g. "notify"). */
	public function getType(): string;

	/** Run the action. Must be side-effect-safe and never throw fatally. */
	public function run(ActionContext $context): void;
}
