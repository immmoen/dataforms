<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\CreateDeckBoardAction;
use OCA\Dataforms\Workflow\NextcloudApiClient;
use OCA\Dataforms\Workflow\RelationResolver;
use OCA\Dataforms\Workflow\ValueInterpolator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * CreateDeckBoardAction (AUT-14, faked client): creates a board + its stacks via
 * exact OCS-style calls, is idempotent (an existing board by title is left
 * alone), and throws when the service account is unconfigured (AUT-20).
 */
class CreateDeckBoardActionTest extends TestCase {
	/** @var list<array{method:string,path:string,body:array<string,mixed>}> */
	private array $calls = [];

	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	/** @param array<int,mixed> $existingBoards */
	private function action(bool $configured, array $existingBoards = []): CreateDeckBoardAction {
		$client = $this->createMock(NextcloudApiClient::class);
		$client->method('isConfigured')->willReturn($configured);
		$client->method('request')->willReturnCallback(function (string $method, string $path, array $body = []) use ($existingBoards) {
			$this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
			if ($method === 'GET' && str_ends_with($path, '/boards')) {
				return ['status' => 200, 'data' => $existingBoards];
			}
			if ($method === 'POST' && str_ends_with($path, '/boards')) {
				return ['status' => 200, 'data' => ['id' => 77]];
			}
			return ['status' => 200, 'data' => null];
		});
		$records = $this->createMock(RecordMapper::class);
		$records->method('findOwnerById')->willReturn('alice');
		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2);
		return new CreateDeckBoardAction($client, $records, new ValueInterpolator(), $relations, $this->settings(), $this->createMock(LoggerInterface::class));
	}

	private function context(array $config = []): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Board', ['name' => 'Acme'], ['title' => 'Project {name}'] + $config);
	}

	public function testThrowsWhenUnconfigured(): void {
		$this->expectException(\RuntimeException::class);
		$this->action(false)->run($this->context());
	}

	public function testCreatesBoardAndStacksFromColumns(): void {
		$this->action(true)->run($this->context(['columns' => 'Backlog, In progress, Done']));
		$create = array_values(array_filter($this->calls, static fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/boards')));
		$this->assertCount(1, $create);
		$this->assertSame('Project Acme', $create[0]['body']['title']); // interpolated
		$stacks = array_values(array_filter($this->calls, static fn ($c) => str_contains($c['path'], '/stacks')));
		$this->assertSame(['Backlog', 'In progress', 'Done'], array_map(static fn ($c) => $c['body']['title'], $stacks));
	}

	public function testIsIdempotentWhenABoardWithTheTitleExists(): void {
		$this->action(true, [['title' => 'Project Acme']])->run($this->context());
		$create = array_filter($this->calls, static fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/boards'));
		$this->assertCount(0, $create, 'an existing board is left untouched');
	}

	public function testUsesDefaultColumnsWhenNoneConfigured(): void {
		$this->action(true)->run($this->context());
		$stacks = array_values(array_filter($this->calls, static fn ($c) => str_contains($c['path'], '/stacks')));
		$this->assertSame(['To do', 'Doing', 'Done'], array_map(static fn ($c) => $c['body']['title'], $stacks));
	}
}
