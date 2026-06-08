<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use Psr\Log\LoggerInterface;

/**
 * Creates (or reuses) a Deck board for the record and sets up its columns — a
 * *composite* action (the stack calls need the board id from the create call).
 * Runs through {@see NextcloudApiClient} as the configured service account.
 *
 * Note: Deck's API lives under /index.php/apps/deck/api, not /ocs.
 *
 * action_config: { title: string, color?: string, columns?: string }.
 */
class CreateDeckBoardAction implements IAction {

	private const API = '/index.php/apps/deck/api/v1.0';
	private const MAX_COLUMNS = 20;
	private const DEFAULT_COLUMNS = ['To do', 'Doing', 'Done'];

	public function __construct(
		private NextcloudApiClient $client,
		private RecordMapper $recordMapper,
		private ValueInterpolator $interpolator,
		private RelationResolver $relationResolver,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'create_deck_board';
	}

	public function isDeferred(): bool {
		return true;
	}

	public function run(ActionContext $context): void {
		if (!$this->client->isConfigured()) {
			return;
		}
		$values = $this->enrich($context);
		$title = trim($this->interpolator->interpolate(trim((string)($context->config['title'] ?? '')), $values));
		if ($title === '') {
			return;
		}

		// Idempotent: if a board with this exact title already exists, leave it.
		if ($this->boardExists($title)) {
			return;
		}

		$color = (string)preg_replace('/[^0-9A-Fa-f]/', '', (string)($context->config['color'] ?? ''));
		if ($color === '') {
			$color = '0082C9';
		}
		$created = $this->client->request('POST', self::API . '/boards', [
			'title' => mb_substr($title, 0, 100),
			'color' => substr($color, 0, 6),
		]);
		if ($created === null || !in_array($created['status'], [200, 201], true)) {
			$this->logger->warning('Dataforms Deck action: could not create board "' . $title . '"');
			return;
		}
		$boardId = (int)($created['data']['id'] ?? 0);
		if ($boardId <= 0) {
			$this->logger->warning('Dataforms Deck action: board create returned no id for "' . $title . '"');
			return;
		}

		$order = 0;
		foreach ($this->columns($context) as $column) {
			$this->client->request('POST', self::API . '/boards/' . $boardId . '/stacks', [
				'title' => mb_substr($column, 0, 100),
				'order' => $order++,
			]);
		}
		$this->logger->info('Dataforms Deck board created for record ' . $context->recordId);
	}

	private function boardExists(string $title): bool {
		$r = $this->client->request('GET', self::API . '/boards');
		$boards = $r['data'] ?? null;
		if (!is_array($boards)) {
			return false;
		}
		foreach ($boards as $board) {
			if (is_array($board) && ($board['title'] ?? null) === $title) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private function columns(ActionContext $context): array {
		$raw = trim((string)($context->config['columns'] ?? ''));
		if ($raw === '') {
			return self::DEFAULT_COLUMNS;
		}
		$cols = array_values(array_filter(array_map('trim', preg_split('/[\n,]/', $raw) ?: []), static fn ($s) => $s !== ''));
		return array_slice($cols, 0, self::MAX_COLUMNS);
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
