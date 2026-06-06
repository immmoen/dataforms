<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCA\Dataforms\BackgroundJob\PurgeDeletedRegistersJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Registers the daily retention job that hard-purges registers soft-deleted past
 * the retention window (audit M1). IJobList::add() is idempotent, so re-running
 * the migration never queues a duplicate.
 */
class Version001200Date20260606130000 extends SimpleMigrationStep {

	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (!$this->jobList->has(PurgeDeletedRegistersJob::class, null)) {
			$this->jobList->add(PurgeDeletedRegistersJob::class);
			$output->info('Dataforms: scheduled the deleted-register retention job.');
		}
	}
}
