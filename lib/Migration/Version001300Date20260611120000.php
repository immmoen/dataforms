<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCA\Dataforms\BackgroundJob\PurgeDeletedRegistersJob;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Automation activity log: one append-only row per action the engine runs, with
 * its outcome (ok / error) and any error message — so an admin or manager can
 * see what fired and what failed, instead of only nextcloud.log. Same daily
 * retention job sweeps old rows (postSchemaChange just re-asserts the job, which
 * gained a log-purge step in this version).
 */
class Version001300Date20260611120000 extends SimpleMigrationStep {

	public function __construct(
		private IJobList $jobList,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_automation_log')) {
			$t = $schema->createTable('df_automation_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('record_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('automation_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('automation_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$t->addColumn('action_type', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
			$t->addColumn('trigger', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ok']);
			$t->addColumn('message', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id', 'created'], 'df_autolog_reg_idx');
			$output->info('Dataforms: created the automation activity log table.');
		}

		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Idempotent: ensure the daily retention job (now also trimming the log) is queued.
		if (!$this->jobList->has(PurgeDeletedRegistersJob::class, null)) {
			$this->jobList->add(PurgeDeletedRegistersJob::class);
		}
	}
}
