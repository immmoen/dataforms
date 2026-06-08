<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

/**
 * Holds the optional **service account** used by the cross-app provisioning
 * actions (Talk, Deck) to call this instance's own API from a background job,
 * which has no user session.
 *
 * The app-password is stored encrypted via {@see ICredentialsManager}; only the
 * internal API base URL (a non-secret) lives in app config. Nothing here ever
 * returns the password to the UI — the admin re-enters it to change it.
 */
class ServiceAccountService {

	private const CRED_KEY = 'cross_app_service_account';
	private const URL_KEY = 'internal_api_url';

	public function __construct(
		private IAppConfig $appConfig,
		private ICredentialsManager $credentials,
	) {
	}

	/** The instance's own internal API base URL (e.g. http://localhost), '' if unset. */
	public function getInternalUrl(): string {
		return rtrim($this->appConfig->getValueString('dataforms', self::URL_KEY, ''), '/');
	}

	/**
	 * @return array{username:string,password:string}|null
	 */
	public function getCredentials(): ?array {
		$c = $this->credentials->retrieve('', self::CRED_KEY);
		if (!is_array($c) || empty($c['username']) || empty($c['password'])) {
			return null;
		}
		return ['username' => (string)$c['username'], 'password' => (string)$c['password']];
	}

	public function isConfigured(): bool {
		return $this->getInternalUrl() !== '' && $this->getCredentials() !== null;
	}

	/**
	 * Save the config. A blank $password keeps the stored one (so the admin can
	 * change the URL/username without re-typing the secret).
	 */
	public function save(string $internalUrl, string $username, string $password): void {
		$this->appConfig->setValueString('dataforms', self::URL_KEY, rtrim(trim($internalUrl), '/'));
		if ($password === '') {
			$existing = $this->getCredentials();
			$password = $existing['password'] ?? '';
		}
		$this->credentials->store('', self::CRED_KEY, [
			'username' => trim($username),
			'password' => $password,
		]);
	}

	public function clear(): void {
		$this->appConfig->deleteKey('dataforms', self::URL_KEY);
		$this->credentials->delete('', self::CRED_KEY);
	}
}
