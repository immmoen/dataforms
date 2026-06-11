<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Exception\ValidationException;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

/**
 * Holds the **service account(s)** used by the cross-app provisioning actions
 * (Talk, Deck) to call this instance's own API from a background job, which has
 * no user session.
 *
 * There is always a **default** account (stored under the original keys, so it
 * keeps working untouched) plus any number of **named extra** accounts, so
 * different registers/actions can provision under different identities. An
 * action selects one by id; an empty/`default` id means the default account.
 *
 * App-passwords are stored encrypted via {@see ICredentialsManager}; only the
 * non-secret bits (URL, username, name) live in app config. The password is
 * never returned to the UI — the admin re-enters it to change it.
 */
class ServiceAccountService {

	public const DEFAULT_ID = 'default';

	// Default account (unchanged from the single-account version).
	private const CRED_KEY = 'cross_app_service_account';
	private const URL_KEY = 'internal_api_url';

	// Extra accounts.
	private const EXTRA_KEY = 'extra_service_accounts';       // app config: list of {id,name,url,username}
	private const EXTRA_CRED_PREFIX = 'dataforms_sa_';        // credentials store: per-account password

	public function __construct(
		private IAppConfig $appConfig,
		private ICredentialsManager $credentials,
	) {
	}

	private function isDefault(string $id): bool {
		return $id === '' || $id === self::DEFAULT_ID;
	}

	// ---- read ------------------------------------------------------------

	/** Internal API base URL for an account (default if $id is '' / 'default'); '' if unset. */
	public function getInternalUrl(string $id = ''): string {
		if ($this->isDefault($id)) {
			return rtrim($this->appConfig->getValueString('dataforms', self::URL_KEY, ''), '/');
		}
		$extra = $this->findExtra($id);
		return $extra !== null ? rtrim((string)($extra['url'] ?? ''), '/') : '';
	}

	/**
	 * @return array{username:string,password:string}|null
	 */
	public function getCredentials(string $id = ''): ?array {
		if ($this->isDefault($id)) {
			$c = $this->credentials->retrieve('', self::CRED_KEY);
			if (!is_array($c) || empty($c['username']) || empty($c['password'])) {
				return null;
			}
			return ['username' => (string)$c['username'], 'password' => (string)$c['password']];
		}
		$extra = $this->findExtra($id);
		if ($extra === null || ($extra['username'] ?? '') === '') {
			return null;
		}
		$c = $this->credentials->retrieve('', self::EXTRA_CRED_PREFIX . $id);
		$password = is_array($c) ? (string)($c['password'] ?? '') : '';
		if ($password === '') {
			return null;
		}
		return ['username' => (string)$extra['username'], 'password' => $password];
	}

	public function isConfigured(string $id = ''): bool {
		return $this->getInternalUrl($id) !== '' && $this->getCredentials($id) !== null;
	}

	/** Whether at least one account (default or extra) is fully configured. */
	public function anyConfigured(): bool {
		if ($this->isConfigured(self::DEFAULT_ID)) {
			return true;
		}
		foreach ($this->extras() as $e) {
			if ($this->isConfigured((string)($e['id'] ?? ''))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Configured accounts as {id,name}, for a manager picking one in the builder.
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public function accountList(): array {
		$out = [];
		if ($this->isConfigured(self::DEFAULT_ID)) {
			$out[] = ['id' => self::DEFAULT_ID, 'name' => 'Default'];
		}
		foreach ($this->extras() as $e) {
			$id = (string)($e['id'] ?? '');
			if ($id !== '' && $this->isConfigured($id)) {
				$out[] = ['id' => $id, 'name' => (string)($e['name'] ?? $id)];
			}
		}
		return $out;
	}

	/**
	 * All accounts for the admin UI (never includes passwords).
	 *
	 * @return array<int,array{id:string,name:string,url:string,username:string,configured:bool,isDefault:bool}>
	 */
	public function adminList(): array {
		$defCred = $this->getCredentials(self::DEFAULT_ID);
		$out = [[
			'id' => self::DEFAULT_ID,
			'name' => 'Default',
			'url' => $this->getInternalUrl(self::DEFAULT_ID),
			'username' => $defCred['username'] ?? '',
			'configured' => $this->isConfigured(self::DEFAULT_ID),
			'isDefault' => true,
		]];
		foreach ($this->extras() as $e) {
			$id = (string)($e['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$out[] = [
				'id' => $id,
				'name' => (string)($e['name'] ?? ''),
				'url' => rtrim((string)($e['url'] ?? ''), '/'),
				'username' => (string)($e['username'] ?? ''),
				'configured' => $this->isConfigured($id),
				'isDefault' => false,
			];
		}
		return $out;
	}

	// ---- write -----------------------------------------------------------

	/**
	 * Save the **default** account. A blank $password keeps the stored one (so the
	 * admin can change the URL/username without re-typing the secret).
	 */
	public function save(string $internalUrl, string $username, string $password): void {
		$this->assertValidUrl($internalUrl);
		$this->appConfig->setValueString('dataforms', self::URL_KEY, rtrim(trim($internalUrl), '/'));
		if ($password === '') {
			$existing = $this->getCredentials(self::DEFAULT_ID);
			$password = $existing['password'] ?? '';
		}
		$this->credentials->store('', self::CRED_KEY, [
			'username' => trim($username),
			'password' => $password,
		]);
	}

	/**
	 * Create or update a **named extra** account. An empty (or unknown) $id creates
	 * a new account with a generated id — an admin-supplied id is never used as a
	 * raw credentials-store key. A blank $password on an existing account keeps the
	 * stored secret. Returns the account id.
	 */
	public function saveExtra(string $id, string $name, string $url, string $username, string $password): string {
		$this->assertValidUrl($url);
		$extras = $this->extras();
		$index = null;
		foreach ($extras as $i => $e) {
			if ((string)($e['id'] ?? '') === $id && $id !== '') {
				$index = $i;
				break;
			}
		}
		if ($index === null) {
			$id = 'sa_' . bin2hex(random_bytes(6)); // generated; never from input
		}

		if ($password === '') {
			$existing = $this->credentials->retrieve('', self::EXTRA_CRED_PREFIX . $id);
			$password = is_array($existing) ? (string)($existing['password'] ?? '') : '';
		}
		$this->credentials->store('', self::EXTRA_CRED_PREFIX . $id, ['password' => $password]);

		$entry = [
			'id' => $id,
			'name' => trim($name) !== '' ? trim($name) : $id,
			'url' => rtrim(trim($url), '/'),
			'username' => trim($username),
		];
		if ($index === null) {
			$extras[] = $entry;
		} else {
			$extras[$index] = $entry;
		}
		$this->setExtras($extras);
		return $id;
	}

	/** Remove an account. The default account is cleared; an extra is deleted entirely. */
	public function remove(string $id): void {
		if ($this->isDefault($id)) {
			$this->clear();
			return;
		}
		$this->credentials->delete('', self::EXTRA_CRED_PREFIX . $id);
		$this->setExtras(array_values(array_filter(
			$this->extras(),
			static fn ($e) => (string)($e['id'] ?? '') !== $id,
		)));
	}

	/** Clear the default account. */
	public function clear(): void {
		$this->appConfig->deleteKey('dataforms', self::URL_KEY);
		$this->credentials->delete('', self::CRED_KEY);
	}

	/**
	 * An empty URL is allowed (an account still being set up). A non-empty one must
	 * be a plain http(s):// URL with a host and no userinfo, so the host guard in
	 * {@see \OCA\Dataforms\Workflow\NextcloudApiClient} behaves predictably.
	 *
	 * @throws ValidationException
	 */
	private function assertValidUrl(string $url): void {
		$url = trim($url);
		if ($url === '') {
			return;
		}
		if (!preg_match('#^https?://#i', $url) || str_contains($url, '@')) {
			throw new ValidationException('The internal API URL must be a plain http(s):// URL');
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new ValidationException('The internal API URL must include a host');
		}
	}

	// ---- extras storage --------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function extras(): array {
		$raw = $this->appConfig->getValueString('dataforms', self::EXTRA_KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
	}

	/**
	 * @param array<int,array<string,mixed>> $extras
	 */
	private function setExtras(array $extras): void {
		$this->appConfig->setValueString('dataforms', self::EXTRA_KEY, json_encode(array_values($extras), JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function findExtra(string $id): ?array {
		foreach ($this->extras() as $e) {
			if ((string)($e['id'] ?? '') === $id) {
				return $e;
			}
		}
		return null;
	}
}
