<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Service;

use OCA\Dataforms\Db\AutomationMapper;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\FormMapper;
use OCA\Dataforms\Db\HistoryMapper;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Db\RegisterMapper;
use OCA\Dataforms\Db\RuleMapper;
use OCA\Dataforms\Db\ShareMapper;
use OCA\Dataforms\Db\ViewMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Hard-purges a soft-deleted register and ALL of its child data across the
 * df_* tables, closing the storage leak where a deleted register left rows in
 * records/values/files/refs/fields/rules/automations/views/forms/history/shares
 * forever (audit M1).
 *
 * Registers are soft-deleted (deleted_at set) so they stay recoverable for a
 * retention window; {@see \OCA\Dataforms\BackgroundJob\PurgeDeletedRegistersJob}
 * calls purgeExpired() daily to hard-delete the ones past that window.
 */
class RegisterPurgeService {
	/** Days a soft-deleted register is retained before it is hard-purged. */
	public const RETENTION_DAYS = 30;

	public function __construct(
		private RegisterMapper $registerMapper,
		private FieldMapper $fieldMapper,
		private RecordMapper $recordMapper,
		private RecordValueMapper $valueMapper,
		private RecordFileMapper $fileMapper,
		private RecordRefMapper $refMapper,
		private RuleMapper $ruleMapper,
		private AutomationMapper $automationMapper,
		private HistoryMapper $historyMapper,
		private ShareMapper $shareMapper,
		private ViewMapper $viewMapper,
		private FormMapper $formMapper,
		private IDBConnection $db,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Purge every register soft-deleted more than RETENTION_DAYS ago.
	 *
	 * @return int number of registers purged
	 */
	public function purgeExpired(): int {
		$cutoff = $this->time->getTime() - self::RETENTION_DAYS * 86400;
		$ids = $this->registerMapper->findSoftDeletedBefore($cutoff);
		$purged = 0;
		foreach ($ids as $id) {
			try {
				$this->purge($id);
				$purged++;
			} catch (\Throwable $e) {
				// One bad register must not stop the rest; log and continue.
				$this->logger->error('Dataforms: failed to purge register ' . $id, ['exception' => $e]);
			}
		}
		return $purged;
	}

	/**
	 * Hard-delete a register and all of its child rows, atomically.
	 *
	 * Order matters: value/file/ref rows reference field ids, so they go before
	 * the fields; incoming relation rows (other registers pointing at this
	 * register's records) are cleared before the records vanish.
	 */
	public function purge(int $registerId): void {
		$this->db->beginTransaction();
		try {
			$fieldIds = $this->fieldMapper->idsForRegister($registerId);

			// Relations first: incoming (foreign) refs by target, then this
			// register's own value/file/ref rows by its field ids.
			$this->refMapper->deleteByTargetRegister($registerId);
			$this->valueMapper->deleteByFieldIds($fieldIds);
			$this->fileMapper->deleteByFieldIds($fieldIds);
			$this->refMapper->deleteByFieldIds($fieldIds);

			$this->historyMapper->deleteByRegister($registerId);
			$this->recordMapper->deleteByRegister($registerId);
			$this->fieldMapper->deleteByRegister($registerId);
			$this->ruleMapper->deleteByRegister($registerId);
			$this->automationMapper->deleteByRegister($registerId);
			$this->shareMapper->deleteByRegister($registerId);
			$this->viewMapper->deleteByRegister($registerId);
			$this->formMapper->deleteByRegister($registerId);
			$this->registerMapper->deleteById($registerId);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
