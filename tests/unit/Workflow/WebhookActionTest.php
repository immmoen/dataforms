<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\WebhookAction;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * WebhookAction (AUT-15): the one outbound action. Asserts the SSRF defence
 * (local addresses refused, redirects forbidden), http(s)-only, and the optional
 * HMAC-SHA256 body signature so a receiver can verify authenticity.
 */
class WebhookActionTest extends TestCase {
	/** @var array{url:string,opts:array<string,mixed>}|null */
	private ?array $call = null;

	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	private function action(): WebhookAction {
		$http = $this->createMock(IClient::class);
		$http->method('post')->willReturnCallback(function (string $url, array $opts = []) {
			$this->call = ['url' => $url, 'opts' => $opts];
			return $this->createMock(\OCP\Http\Client\IResponse::class);
		});
		$svc = $this->createMock(IClientService::class);
		$svc->method('newClient')->willReturn($http);
		return new WebhookAction($svc, $this->settings(), $this->createMock(LoggerInterface::class));
	}

	private function context(array $config): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Hook', ['title' => 'Hello'], $config);
	}

	public function testSkipsNonHttpUrls(): void {
		$action = $this->action();
		$action->run($this->context(['url' => 'ftp://x/y']));
		$action->run($this->context(['url' => '']));
		$this->assertNull($this->call, 'no outbound call for a non-http(s) URL');
	}

	public function testPostsWithSsrfGuardsAndNoRedirects(): void {
		$this->action()->run($this->context(['url' => 'https://hooks.example/in']));
		$this->assertNotNull($this->call);
		$this->assertFalse($this->call['opts']['allow_redirects']);
		$this->assertFalse($this->call['opts']['nextcloud']['allow_local_address']);
	}

	public function testSignsTheBodyWithHmacWhenASecretIsSet(): void {
		$this->action()->run($this->context(['url' => 'https://hooks.example/in', 'secret' => 's3cr3t']));
		$body = $this->call['opts']['body'];
		$expected = 'sha256=' . hash_hmac('sha256', $body, 's3cr3t');
		$this->assertSame($expected, $this->call['opts']['headers']['X-DataForms-Signature']);
		// The payload carries the record event.
		$decoded = json_decode($body, true);
		$this->assertSame(9, $decoded['recordId']);
		$this->assertSame(['title' => 'Hello'], $decoded['values']);
	}

	public function testNoSignatureHeaderWithoutASecret(): void {
		$this->action()->run($this->context(['url' => 'https://hooks.example/in']));
		$this->assertArrayNotHasKey('X-DataForms-Signature', $this->call['opts']['headers']);
	}
}
