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
 * Join table for multi-valued file-attachment fields. A file field can hold
 * one or more files; each is referenced by its Nextcloud file id (never stored
 * as a blob).
 */
class Version000300Date20260603160000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_rec_files')) {
			$t = $schema->createTable('df_rec_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('record_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('field_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['record_id'], 'df_recfiles_rec_idx');
			$t->addIndex(['record_id', 'field_id'], 'df_recfiles_rf_idx');
		}

		return $schema;
	}
}
