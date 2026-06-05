<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

/**
 * Maps action_type → IAction. Add a new action by adding its class to the
 * constructor; nothing else in the engine changes.
 */
class ActionRegistry {
	/** @var array<string,IAction> */
	private array $actions = [];

	public function __construct(NotifyAction $notify) {
		$this->register($notify);
	}

	private function register(IAction $action): void {
		$this->actions[$action->getType()] = $action;
	}

	/** Supported action type ids. @return string[] */
	public function types(): array {
		return array_keys($this->actions);
	}

	public function get(string $type): ?IAction {
		return $this->actions[$type] ?? null;
	}
}
