<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Settings;

use OCA\Dataforms\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin → DataForms: a small "API console" that documents the REST API and how
 * to authenticate to it. It implements nothing itself — the API and the
 * app-password auth already exist; this page just makes them discoverable.
 */
class AdminSettings implements ISettings {

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$params = [
			'apiBase' => $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/dataforms/api/v1/'),
			'securityUrl' => $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'security']),
		];
		return new TemplateResponse(Application::APP_ID, 'admin-settings', $params);
	}

	public function getSection(): string {
		return 'dataforms';
	}

	public function getPriority(): int {
		return 50;
	}
}
