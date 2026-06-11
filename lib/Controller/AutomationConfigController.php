<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\AppInfo\Application;
use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Admin-only management of the instance-wide automation settings: which action
 * types are available, and the operational limits/defaults. Methods carry NO
 * #[NoAdminRequired], so the framework requires an admin; the OCS-APIRequest
 * header provides CSRF protection.
 */
class AutomationConfigController extends OCSController {

	public function __construct(
		IRequest $request,
		private WorkflowSettings $settings,
		private ActionRegistry $registry,
		private ServiceAccountService $account,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function status(): DataResponse {
		$disabled = $this->settings->disabledActions();
		$actions = array_map(fn (string $type): array => [
			'type' => $type,
			'enabled' => !in_array($type, $disabled, true),
			'needsServiceAccount' => $this->settings->needsServiceAccount($type),
		], $this->registry->types());

		$snapshot = $this->settings->toArray();
		return new DataResponse([
			'actions' => $actions,
			'limits' => $snapshot['limits'],
			'deckColumns' => $snapshot['deckColumns'],
			'defaults' => $snapshot['defaults'],
			'defaultDeckColumns' => implode(', ', WorkflowSettings::DEFAULT_DECK_COLUMNS),
			'serviceAccountConfigured' => $this->account->isConfigured(),
		]);
	}

	/**
	 * @param string[] $disabled action type ids to disable
	 * @param array<string,mixed> $limits
	 */
	public function save(array $disabled = [], array $limits = [], string $deckColumns = ''): DataResponse {
		// Only accept known action type ids, so a stale/garbage id can't be stored.
		$known = $this->registry->types();
		$this->settings->setDisabledActions(array_values(array_filter(
			array_map('strval', $disabled),
			static fn ($t) => in_array($t, $known, true),
		)));
		$this->settings->setLimits($limits);
		$this->settings->setDeckColumns($deckColumns);
		return $this->status();
	}
}
