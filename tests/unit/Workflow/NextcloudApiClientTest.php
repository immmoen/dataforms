<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Workflow\NextcloudApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * NextcloudApiClient: the constrained transport for the cross-app actions. It
 * only allows relative paths into the two NC API entry points, keeps the call on
 * the configured host, attaches the service-account credentials, forbids
 * redirects, and returns null when unconfigured / for a bad path.
 */
class NextcloudApiClientTest extends TestCase {
	private function client(ServiceAccountService $account, ?IResponse $response, array &$calls = []): NextcloudApiClient {
		$http = $this->createMock(IClient::class);
		$grab = function (string $url, array $opts = []) use (&$calls, $response): IResponse {
			$calls[] = ['url' => $url, 'opts' => $opts];
			if ($response === null) {
				throw new \RuntimeException('boom');
			}
			return $response;
		};
		$http->method('post')->willReturnCallback($grab);
		$http->method('get')->willReturnCallback($grab);
		$svc = $this->createMock(IClientService::class);
		$svc->method('newClient')->willReturn($http);
		return new NextcloudApiClient($account, $svc, $this->createMock(LoggerInterface::class));
	}

	private function account(string $base, ?array $cred): ServiceAccountService {
		$a = $this->createMock(ServiceAccountService::class);
		$a->method('getInternalUrl')->willReturn($base);
		$a->method('getCredentials')->willReturn($cred);
		return $a;
	}

	private function response(int $status, string $body): IResponse {
		$r = $this->createMock(IResponse::class);
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn($body);
		return $r;
	}

	public function testReturnsNullWhenUnconfigured(): void {
		$client = $this->client($this->account('', null), $this->response(200, '{}'));
		$this->assertNull($client->request('GET', '/ocs/v2.php/x'));
	}

	public function testRejectsPathsOutsideTheApiEntryPoints(): void {
		$client = $this->client($this->account('https://nc.example', ['username' => 'svc', 'password' => 'pw']), $this->response(200, '{}'));
		$this->assertNull($client->request('GET', '/etc/passwd'));     // not an API path
		$this->assertNull($client->request('GET', '/ocs/../secret')); // traversal
		$this->assertNull($client->request('GET', 'ocs/x'));          // not absolute
	}

	public function testSendsCredentialsAndForbidsRedirectsOnAValidCall(): void {
		$calls = [];
		$client = $this->client($this->account('https://nc.example', ['username' => 'svc', 'password' => 'pw']), $this->response(200, '{"ok":true}'), $calls);
		$out = $client->request('POST', '/ocs/v2.php/apps/spreed/api/v4/room?format=json', ['roomName' => 'X']);

		$this->assertSame(200, $out['status']);
		$this->assertSame(['ok' => true], $out['data']);
		$opts = $calls[0]['opts'];
		$this->assertSame(['svc', 'pw'], $opts['auth']);
		$this->assertFalse($opts['allow_redirects']);
		$this->assertSame(['roomName' => 'X'], $opts['json']);
		$this->assertStringStartsWith('https://nc.example/ocs/', $calls[0]['url']);
	}

	public function testNetworkFailureYieldsAStatusZeroResult(): void {
		$client = $this->client($this->account('https://nc.example', ['username' => 'svc', 'password' => 'pw']), null);
		$out = $client->request('POST', '/ocs/v2.php/apps/x', ['a' => 1]);
		$this->assertSame(0, $out['status']);
		$this->assertNull($out['data']);
	}

	public function testTestHelperReportsConnectivity(): void {
		$client = $this->client($this->account('https://nc.example', ['username' => 'svc', 'password' => 'pw']), $this->response(200, '{}'));
		$this->assertSame(['ok' => true, 'status' => 200], $client->test());
	}
}
