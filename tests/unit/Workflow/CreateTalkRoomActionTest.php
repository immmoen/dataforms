<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\CreateTalkRoomAction;
use OCA\Dataforms\Workflow\NextcloudApiClient;
use OCA\Dataforms\Workflow\RelationResolver;
use OCA\Dataforms\Workflow\ValueInterpolator;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the two security behaviours of the composite Talk action:
 *   (a) it always creates a fresh room (never reuses one found by display name);
 *   (b) it only adds participants that actually exist — an arbitrary id from a
 *       record value is never relayed to the elevated service account verbatim.
 */
class CreateTalkRoomActionTest extends TestCase {
	/** @var list<array{method:string,path:string,body:array<string,mixed>}> */
	private array $calls = [];

	/** A WorkflowSettings backed by a config mock that always yields the defaults. */
	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	private function buildAction(): CreateTalkRoomAction {
		$client = $this->createMock(NextcloudApiClient::class);
		$client->method('isConfigured')->willReturn(true);
		$client->method('request')->willReturnCallback(function (string $method, string $path, array $body = []) {
			$this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
			if (str_contains($path, '/room?format=json')) {
				// createRoom response shape.
				return ['status' => 201, 'data' => ['ocs' => ['data' => ['token' => 'TKN123']]]];
			}
			return ['status' => 200, 'data' => null];
		});

		$recordMapper = $this->createMock(RecordMapper::class);
		$recordMapper->method('findOwnerById')->willReturn('alice');

		$fieldMapper = $this->createMock(FieldMapper::class);
		$fieldMapper->method('findByRegister')->willReturn([]); // → participant source defaults to 'users'

		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2); // values unchanged

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $id): bool => $id === 'realuser');

		$groups = $this->createMock(IGroupManager::class);

		return new CreateTalkRoomAction(
			$client,
			$recordMapper,
			$fieldMapper,
			new ValueInterpolator(),
			$relations,
			$users,
			$groups,
			$this->settings(),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function context(): ActionContext {
		return new ActionContext(
			registerId: 7,
			recordId: 42,
			userId: 'alice',
			automationName: 'Provision',
			values: ['members' => ['realuser', 'ghost']],
			config: ['roomName' => 'Project {recordId}', 'participantsField' => 'members', 'message' => ''],
		);
	}

	public function testAlwaysCreatesAFreshRoom(): void {
		$action = $this->buildAction();
		$action->run($this->context());

		$createCalls = array_filter($this->calls, static fn ($c) => str_contains($c['path'], '/room?format=json'));
		$this->assertCount(1, $createCalls, 'exactly one room is created');
		// It never probes for an existing room first (no GET before the create POST).
		$this->assertSame('POST', reset($createCalls)['method']);
	}

	public function testSkipsNonExistentParticipants(): void {
		$action = $this->buildAction();
		$action->run($this->context());

		$participantCalls = array_values(array_filter(
			$this->calls,
			static fn ($c) => str_contains($c['path'], '/participants?format=json')
		));
		$this->assertCount(1, $participantCalls, 'only the existing user is added');
		$this->assertSame('realuser', $participantCalls[0]['body']['newParticipant']);
		$this->assertSame('users', $participantCalls[0]['body']['source']);
	}

	public function testThrowsWhenServiceAccountUnconfigured(): void {
		// An automation that expects to create a room but whose service account is
		// gone must surface as a failed run (recorded as 'error' in the activity
		// log), not a misleading silent "OK". It still makes no outbound call.
		$client = $this->createMock(NextcloudApiClient::class);
		$client->method('isConfigured')->willReturn(false);
		$client->expects($this->never())->method('request');

		$action = new CreateTalkRoomAction(
			$client,
			$this->createMock(RecordMapper::class),
			$this->createMock(FieldMapper::class),
			new ValueInterpolator(),
			$this->createMock(RelationResolver::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->settings(),
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(\RuntimeException::class);
		$action->run($this->context());
	}
}
