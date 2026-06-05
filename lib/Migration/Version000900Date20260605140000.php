<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Append-only record history (§4.9): one row per create / update / delete,
 * capturing who, when, what action, and which fields changed.
 */
class Version000900Date20260605140000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_history')) {
			$t = $schema->createTable('df_history');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('record_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
			// Human-readable one-liner and an optional JSON detail of changes.
			$t->addColumn('summary', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['record_id'], 'df_hist_rec_idx');
			$t->addIndex(['register_id'], 'df_hist_reg_idx');
		}

		return $schema;
	}
}
