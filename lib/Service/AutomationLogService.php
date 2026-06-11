<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\Automation;
use OCA\Dataforms\Db\AutomationLog;
use OCA\Dataforms\Db\AutomationLogMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Records and reads the automation activity log. The engine calls record() after
 * each action (best-effort — logging never breaks an automation); managers read
 * their register's recent runs via listForRegister(). A daily sweep trims old
 * rows so the table can't grow without bound.
 */
class AutomationLogService {

	public const STATUS_OK = 'ok';
	public const STATUS_ERROR = 'error';

	/** Activity older than this is purged by the daily retention job. */
	public const RETENTION_DAYS = 30;

	public function __construct(
		private AutomationLogMapper $mapper,
		private RegisterService $registerService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Append one outcome row. Best-effort: a logging failure is swallowed so it can
	 * never turn a working automation into a broken one.
	 */
	public function record(Automation $automation, int $registerId, ?int $recordId, string $status, ?string $message = null): void {
		try {
			$entry = new AutomationLog();
			$entry->setRegisterId($registerId);
			$entry->setRecordId($recordId);
			$entry->setAutomationId($automation->getId());
			$entry->setAutomationName($automation->getName());
			$entry->setActionType($automation->getActionType());
			$entry->setTrigger($automation->getTrigger());
			$entry->setStatus($status === self::STATUS_ERROR ? self::STATUS_ERROR : self::STATUS_OK);
			$entry->setMessage($message !== null ? mb_substr($message, 0, 1000) : null);
			$entry->setCreated($this->time->getTime());
			$this->mapper->insert($entry);
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms: could not write automation log entry', ['exception' => $e]);
		}
	}

	/**
	 * Recent activity for a register. Viewing needs Manage on the register, like
	 * the automations themselves.
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws NotFoundException
	 * @throws \OCA\Dataforms\Exception\ForbiddenException
	 */
	public function listForRegister(string $userId, int $registerId, int $limit = 100): array {
		$this->registerService->findManageable($userId, $registerId);
		return array_map(static fn (AutomationLog $l) => $l->jsonSerialize(), $this->mapper->findByRegister($registerId, $limit));
	}

	/** Daily retention sweep — drop activity older than RETENTION_DAYS. @return int rows deleted */
	public function purgeExpired(): int {
		$cutoff = $this->time->getTime() - self::RETENTION_DAYS * 86400;
		return $this->mapper->deleteOlderThan($cutoff);
	}
}
