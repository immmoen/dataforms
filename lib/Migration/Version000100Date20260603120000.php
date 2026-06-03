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
 * Phase 1 core schema: registers, fields, records and the EAV value store.
 *
 * Tables use the short prefix `df_` (not the full app id) deliberately: the
 * App Store/portability checks limit identifier length, and names like
 * `oc_dataforms_record_values` would overflow on some engines. All record
 * DATA lives in typed value columns (never JSON) so it stays filterable and
 * sortable across MySQL/MariaDB, PostgreSQL and SQLite. JSON is used only for
 * opaque, never-queried config blobs.
 */
class Version000100Date20260603120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// ---- registers -------------------------------------------------
		if (!$schema->hasTable('df_registers')) {
			$t = $schema->createTable('df_registers');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('icon', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$t->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['owner'], 'df_reg_owner_idx');
		}

		// ---- fields (schema of a register) -----------------------------
		if (!$schema->hasTable('df_fields')) {
			$t = $schema->createTable('df_fields');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('machine_name', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			// Opaque, never-queried config (options, min/max, precision, ...).
			$t->addColumn('config', Types::TEXT, ['notnull' => false]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Nextcloud portability rule: NOT NULL booleans cannot default to
			// false (Oracle), so boolean flags are nullable; treat null as false.
			$t->addColumn('mandatory', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('is_unique', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('default_value', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_fields_reg_idx');
			$t->addUniqueIndex(['register_id', 'machine_name'], 'df_fields_mname_uniq');
		}

		// ---- records (one row per stored entry) ------------------------
		if (!$schema->hasTable('df_records')) {
			$t = $schema->createTable('df_records');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_rec_reg_idx');
		}

		// ---- record_values (EAV, exactly one typed column populated) ---
		if (!$schema->hasTable('df_record_values')) {
			$t = $schema->createTable('df_record_values');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('record_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('field_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('value_string', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$t->addColumn('value_number', Types::DECIMAL, ['notnull' => false, 'precision' => 20, 'scale' => 6]);
			$t->addColumn('value_datetime', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('value_bool', Types::BOOLEAN, ['notnull' => false]);
			$t->addColumn('value_file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('value_ref_record_id', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['record_id'], 'df_rv_rec_idx');
			$t->addIndex(['field_id'], 'df_rv_field_idx');
			// Short, safely-indexable typed columns get composite indexes for
			// filter/sort. value_string is left out here (engine key-length
			// limits); a prefixed index is added in the performance phase.
			$t->addIndex(['field_id', 'value_number'], 'df_rv_fnum_idx');
			$t->addIndex(['field_id', 'value_datetime'], 'df_rv_fdate_idx');
			$t->addIndex(['field_id', 'value_bool'], 'df_rv_fbool_idx');
		}

		// ---- shares (register-level ACL) -------------------------------
		if (!$schema->hasTable('df_shares')) {
			$t = $schema->createTable('df_shares');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			// 0 = user, 1 = group (mirrors Nextcloud share-type convention).
			$t->addColumn('share_type', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('share_with', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Bitmask: 1 = read, 2 = write, 4 = manage.
			$t->addColumn('permissions', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_shares_reg_idx');
			$t->addUniqueIndex(['register_id', 'share_type', 'share_with'], 'df_shares_uniq');
		}

		return $schema;
	}
}
