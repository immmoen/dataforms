<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Creates (or reuses) a Talk conversation for the record, adds participants from
 * a user/group field, and posts a welcome message — a *composite* action,
 * because each call depends on the previous one's output (the room token). It
 * runs through {@see NextcloudApiClient} as the configured service account, so it
 * only works once an admin has set that up.
 *
 * action_config: { roomName: string, participantsField?: string, message?: string }.
 */
class CreateTalkRoomAction implements IAction {

	private const ROOM_GROUP = 2; // spreed roomType: group conversation

	public function __construct(
		private NextcloudApiClient $client,
		private RecordMapper $recordMapper,
		private FieldMapper $fieldMapper,
		private ValueInterpolator $interpolator,
		private RelationResolver $relationResolver,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private WorkflowSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'create_talk_room';
	}

	public function isDeferred(): bool {
		return true; // outbound API calls: run off the request thread
	}

	public function run(ActionContext $context): void {
		$accountId = (string)($context->config['serviceAccount'] ?? '');
		if (!$this->client->isConfigured($accountId)) {
			// The automation expects to create a room but its service account is
			// gone (removed, or the selected named account no longer exists).
			// Surface it as a failed run rather than logging a misleading "OK".
			throw new \RuntimeException('No service account is configured for the "create_talk_room" action');
		}
		$values = $this->enrich($context);
		$roomName = trim($this->interpolator->interpolate(trim((string)($context->config['roomName'] ?? '')), $values));
		if ($roomName === '') {
			return;
		}

		// Always create a fresh room. (Reusing a room found by display name would
		// let a record's field value name an unrelated existing conversation and
		// inject participants/messages into it — so we never reuse by name. These
		// actions are restricted to the 'create' trigger, so each record provisions
		// its own room exactly once.)
		$token = $this->createRoom($roomName, $accountId);
		if ($token === null) {
			// Surfaced to the engine → recorded as a failed run in the activity log.
			throw new \RuntimeException('Talk room "' . $roomName . '" could not be created');
		}

		$this->addParticipants($context, $values, $token, $accountId);

		$message = trim($this->interpolator->interpolate((string)($context->config['message'] ?? ''), $values));
		if ($message !== '') {
			$this->client->request('POST', '/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($token) . '?format=json', ['message' => $message], $accountId);
		}
		$this->logger->info('Dataforms Talk room ready for record ' . $context->recordId);
	}

	private function createRoom(string $name, string $accountId): ?string {
		$r = $this->client->request('POST', '/ocs/v2.php/apps/spreed/api/v4/room?format=json', [
			'roomType' => self::ROOM_GROUP,
			'roomName' => mb_substr($name, 0, 254),
		], $accountId);
		if ($r === null || !in_array($r['status'], [200, 201], true)) {
			return null;
		}
		$token = (string)($r['data']['ocs']['data']['token'] ?? '');
		return $token !== '' ? $token : null;
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function addParticipants(ActionContext $context, array $values, string $token, string $accountId): void {
		$pf = trim((string)($context->config['participantsField'] ?? ''));
		if ($pf === '') {
			return;
		}
		$source = $this->participantSource($context->registerId, $pf);
		$added = 0;
		$maxParticipants = $this->settings->maxParticipants();
		foreach ($this->idList($values[$pf] ?? null) as $id) {
			if ($added >= $maxParticipants) {
				break;
			}
			// Only add principals that actually exist — never relay an arbitrary
			// id from a record value to the elevated service account verbatim.
			if ($source === 'groups' ? !$this->groupManager->groupExists($id) : !$this->userManager->userExists($id)) {
				continue;
			}
			$this->client->request(
				'POST',
				'/ocs/v2.php/apps/spreed/api/v4/room/' . rawurlencode($token) . '/participants?format=json',
				['newParticipant' => $id, 'source' => $source],
				$accountId,
			);
			$added++;
		}
	}

	/** 'groups' if the participants field is a group field, else 'users'. */
	private function participantSource(int $registerId, string $machineName): string {
		foreach ($this->fieldMapper->findByRegister($registerId) as $f) {
			if ($f->getMachineName() === $machineName) {
				return $f->getType() === 'group' ? 'groups' : 'users';
			}
		}
		return 'users';
	}

	/**
	 * @param mixed $raw
	 * @return string[]
	 */
	private function idList($raw): array {
		if (is_array($raw)) {
			$out = [];
			foreach ($raw as $x) {
				$id = is_array($x) ? (string)($x['id'] ?? '') : trim((string)$x);
				if ($id !== '') {
					$out[] = $id;
				}
			}
			return $out;
		}
		$s = trim((string)$raw);
		return $s === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $s)), static fn ($x) => $x !== ''));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function enrich(ActionContext $context): array {
		$owner = $this->recordMapper->findOwnerById($context->recordId);
		if ($owner === null || $owner === '') {
			return $context->values;
		}
		return $this->relationResolver->enrich($owner, $context->registerId, $context->values);
	}
}
