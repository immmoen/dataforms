<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Service\ServiceAccountService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Makes authenticated calls to THIS instance's own API as the configured service
 * account — the transport the cross-app provisioning actions (Talk, Deck) use
 * from a background job. The call target is constrained to the admin-configured
 * internal base URL and to the two Nextcloud API entry points (/ocs/ and
 * /index.php/apps/), so it can never become a generic outbound HTTP tool.
 */
class NextcloudApiClient {

	public function __construct(
		private ServiceAccountService $account,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(string $accountId = ''): bool {
		return $this->account->isConfigured($accountId);
	}

	/**
	 * @param array<string,mixed> $jsonBody
	 * @param string $accountId which service account to act as ('' = the default)
	 * @return array{status:int,data:mixed}|null null = not configured / bad path
	 */
	public function request(string $method, string $path, array $jsonBody = [], string $accountId = ''): ?array {
		$base = $this->account->getInternalUrl($accountId);
		$cred = $this->account->getCredentials($accountId);
		if ($base === '' || $cred === null) {
			$this->logger->warning('Dataforms: cross-app service account not configured');
			return null;
		}
		if (!$this->validPath($path)) {
			$this->logger->warning('Dataforms: rejected cross-app API path');
			return null;
		}
		$url = $base . $path;
		// The resolved URL must stay on the configured instance host.
		if (parse_url($url, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) {
			return null;
		}

		$opts = [
			'headers' => ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
			'auth' => [$cred['username'], $cred['password']],
			'nextcloud' => ['allow_local_address' => true], // internal, same-host call
			// Never follow redirects: with local addresses permitted and the
			// service-account credentials attached, a 30x to a loopback/metadata
			// host would be an SSRF + credential-leak (the host guard only checks
			// the initial URL). All OCS/Deck calls are single-hop.
			'allow_redirects' => false,
			'timeout' => 15,
			'connect_timeout' => 5,
			'http_errors' => false,
		];
		if ($jsonBody !== []) {
			$opts['json'] = $jsonBody;
		}

		try {
			$client = $this->clientService->newClient();
			$res = match (strtolower($method)) {
				'get' => $client->get($url, $opts),
				'post' => $client->post($url, $opts),
				'put' => $client->put($url, $opts),
				'delete' => $client->delete($url, $opts),
				default => null,
			};
			if ($res === null) {
				return null;
			}
			$body = (string)$res->getBody();
			$decoded = json_decode($body, true);
			return ['status' => $res->getStatusCode(), 'data' => $decoded ?? $body];
		} catch (\Throwable $e) {
			// Log only the class + message, never the full exception object (whose
			// serialized request context could in principle carry the auth header).
			$this->logger->warning('Dataforms cross-app call failed: ' . strtoupper($method) . ' ' . $path
				. ' — ' . get_class($e) . ': ' . $e->getMessage());
			return ['status' => 0, 'data' => null];
		}
	}

	/** Connectivity/auth check for the admin "Test" button. @return array{ok:bool,status:int} */
	public function test(string $accountId = ''): array {
		$r = $this->request('GET', '/ocs/v2.php/cloud/capabilities?format=json', [], $accountId);
		return ['ok' => $r !== null && $r['status'] === 200, 'status' => $r['status'] ?? 0];
	}

	/**
	 * Only relative paths into the two Nextcloud API entry points are allowed; no
	 * scheme/host, no traversal — the action supplies a fixed endpoint, never a
	 * user-controlled URL.
	 */
	private function validPath(string $path): bool {
		if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
			return false;
		}
		return str_starts_with($path, '/ocs/') || str_starts_with($path, '/index.php/apps/');
	}
}
