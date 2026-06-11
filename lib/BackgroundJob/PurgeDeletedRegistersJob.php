<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\BackgroundJob;

use OCA\Dataforms\Service\AutomationLogService;
use OCA\Dataforms\Service\RegisterPurgeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily retention sweep: hard-purges registers that have been soft-deleted for
 * longer than {@see RegisterPurgeService::RETENTION_DAYS}, reclaiming the storage
 * their records/values/fields/etc. occupy (audit M1). Registered once via the
 * Version001200 migration; runs off the Nextcloud background-job (cron) queue.
 */
class PurgeDeletedRegistersJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private RegisterPurgeService $purge,
		private AutomationLogService $logService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60); // daily
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$purged = $this->purge->purgeExpired();
			if ($purged > 0) {
				$this->logger->info('Dataforms: purged ' . $purged . ' soft-deleted register(s) past retention');
			}
			$logsPurged = $this->logService->purgeExpired();
			if ($logsPurged > 0) {
				$this->logger->info('Dataforms: trimmed ' . $logsPurged . ' automation-log entries past retention');
			}
		} catch (\Throwable $e) {
			$this->logger->error('Dataforms retention job failed', ['exception' => $e]);
		}
	}
}
