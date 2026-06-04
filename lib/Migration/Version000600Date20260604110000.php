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
 * Join table for multi-valued relation fields. A relation field can reference
 * one or more records in another register; referential integrity (null/block/
 * cascade on delete) is enforced via the target_record_id index.
 */
class Version000600Date20260604110000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_rec_refs')) {
			$t = $schema->createTable('df_rec_refs');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('record_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('field_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('target_record_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['record_id'], 'df_recrefs_rec_idx');
			$t->addIndex(['record_id', 'field_id'], 'df_recrefs_rf_idx');
			$t->addIndex(['target_record_id'], 'df_recrefs_tgt_idx');
		}

		return $schema;
	}
}
