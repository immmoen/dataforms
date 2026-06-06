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
 * Scalability + lifecycle schema:
 *
 * - df_records gets composite indexes on (register_id, updated) and
 *   (register_id, created) so the default record list (sorted by updated/created
 *   within a register) is index-served instead of a filesort at scale (audit M8).
 * - df_fields gets a nullable deleted_at column so a field can be soft-deleted:
 *   the row survives as a name tombstone (its machine_name stays reserved, so a
 *   reused name can never silently re-bind a stale rule to a new field), while
 *   active queries filter it out (audit M2).
 */
class Version001100Date20260606120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('df_records')) {
			$t = $schema->getTable('df_records');
			if (!$t->hasIndex('df_rec_reg_upd_idx')) {
				$t->addIndex(['register_id', 'updated'], 'df_rec_reg_upd_idx');
			}
			if (!$t->hasIndex('df_rec_reg_crt_idx')) {
				$t->addIndex(['register_id', 'created'], 'df_rec_reg_crt_idx');
			}
		}

		if ($schema->hasTable('df_fields')) {
			$t = $schema->getTable('df_fields');
			if (!$t->hasColumn('deleted_at')) {
				$t->addColumn('deleted_at', Types::BIGINT, ['notnull' => false]);
			}
		}

		return $schema;
	}
}
