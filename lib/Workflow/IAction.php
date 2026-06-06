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

	/**
	 * Whether this action must run off the request thread (in a background job).
	 *
	 * Deferred actions are ones with slow or external side effects — outbound
	 * HTTP, SMTP — that must never block the record write path or let a hung
	 * endpoint exhaust the PHP worker pool. Inline actions (cheap, internal,
	 * loop-safe — e.g. an in-app notification or a direct field write) return
	 * false and run synchronously in the listener.
	 */
	public function isDeferred(): bool;
}
