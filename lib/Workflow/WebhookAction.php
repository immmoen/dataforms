<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Calls an outbound webhook with the record event payload — the one action that
 * leaves the instance, so it is explicit, manager-configured, logged, and
 * time-limited. An optional shared secret signs the body (HMAC-SHA256) so the
 * receiver can verify authenticity.
 *
 * action_config: { url: string, secret?: string }.
 */
class WebhookAction implements IAction {

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'webhook';
	}

	public function isDeferred(): bool {
		return true; // outbound HTTP: run off the request thread (background job)
	}

	public function run(ActionContext $context): void {
		$url = trim((string)($context->config['url'] ?? ''));
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return; // http(s) only
		}

		$payload = json_encode([
			'automation' => $context->automationName,
			'registerId' => $context->registerId,
			'recordId' => $context->recordId,
			'userId' => $context->userId,
			'values' => $context->values,
		], JSON_THROW_ON_ERROR);

		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent' => 'Nextcloud-DataForms',
		];
		$secret = (string)($context->config['secret'] ?? '');
		if ($secret !== '') {
			$headers['X-DataForms-Signature'] = 'sha256=' . hash_hmac('sha256', $payload, $secret);
		}

		try {
			$this->clientService->newClient()->post($url, [
				'body' => $payload,
				'headers' => $headers,
				'timeout' => 10,
				'connect_timeout' => 5,
				// SSRF defence: refuse internal/loopback/link-local targets
				// regardless of the instance's allow_local_remote_servers setting,
				// and never follow redirects (a 30x to a local address would
				// otherwise bypass the check via DNS-rebind / redirect chaining).
				'nextcloud' => [
					'allow_local_address' => false,
				],
				'allow_redirects' => false,
			]);
			$this->logger->info('Dataforms webhook delivered', ['url' => $url, 'record' => $context->recordId]);
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms webhook failed', ['url' => $url, 'exception' => $e]);
		}
	}
}
